<?php
declare(strict_types=1);
namespace Wx\Systems\Security;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;
use Wx\Systems\Api\ApiOperationException;
use Wx\Systems\Api\RateLimiter;
use Wx\Systems\Api\Envelope;

/**
 * 页面登录/登出的安全边界 —— 只做三件事：限流、调用会话接口、写/清凭据。
 *
 * 为什么不直接让页面执行 auth.session.create：登录是口令校验，绝不能由承载层
 * 决定「试几次算多、锁定怎么判」。这里复用同一个 SessionOperations（账号锁定、
 * 禁用、口令策略全在内），页面只拿到结构化结果。
 *
 * Cookie 的写法与清法只在 SessionCookie 一处；本类不拼接 Cookie 字符串。
 * 密码不出现在返回值、日志与异常消息里。
 *
 * @consumes auth.session.create 浏览器登录必须走同一个会话接口（锁定/禁用判定同源）
 * @consumes auth.session.revoke 登出走同一执行器：只撤 kind=session，不伤 API 脚本令牌
 * @consumes auth.logout 换号登录前的残留凭据登出走通用撤销（revoked=false 幂等）
 */
final class PageSession
{
    public function __construct(
        private Kernel $kernel,
        private ?ApiRegistryInterface $registry,
        private ?SessionManager $sessions
    ) {}

    public function role(): string { return 'page_session'; }

    /** @return array<string, mixed> token / max_age / expires_at / role */
    public function login(string $username, string $password, ?string $ip): array
    {
        if ($this->registry === null || $this->sessions === null) {
            throw new ApiOperationException('数据库未接入，无法建立浏览器会话', 50004);
        }
        $this->throttle((string) $ip);

        return $this->registry->execute('auth.session.create', [
            'username' => $username, 'password' => $password,
        ]);
    }

    /** 登录成功才写 Cookie：失败响应绝不携带会话凭据。 */
    public function apply(Response $response, array $session): void
    {
        $token = (string) ($session['token'] ?? '');
        if ($token === '') return;
        $response->setHeader('Set-Cookie', SessionCookie::header(
            $token, (int) ($session['max_age'] ?? $this->kernel->config()->get('security.session.ttl', 7200))
        ));
    }

    /**
     * 表单票据只证明「谁在提交」，不含 token_hash；改密要保留当前会话，
     * 必须把当前 Cookie 的摘要补回身份。这里只读会话 Cookie，不读 wx_token。
     * @param array<string, mixed> $identity
     * @return array<string, mixed>
     */
    public function withSessionHash(Request $request, array $identity): array
    {
        $token = SessionCookie::read($request);
        if ($token !== '') $identity['token_hash'] = hash('sha256', $token);
        return $identity;
    }

    /**
     * 登出：撤销当前会话令牌并清 Cookie；令牌不在库中时仍然清 Cookie。
     *
     * v0.47 起撤销动作走 auth.session.revoke 执行器：与 API 客户端同一套判定，
     * 只撤 kind='session' 的行，用户的 API 脚本令牌不受影响。接口清单未装配或
     * 执行器异常时降级直撤（登出不能因为一条 UPDATE 的路径不可用而失败），
     * 无论撤销结果如何 Cookie 都会清掉 —— 清凭据是无条件的。
     *
     * @return array{revoked: bool} revoked=false 含「本来就没登录」与「令牌已失效」
     */
    public function logout(Request $request, Response $response): array
    {
        $token = SessionCookie::read($request);
        $revoked = false;
        if ($token !== '') {
            $hash = hash('sha256', $token);
            if ($this->registry !== null) {
                try {
                    $out = $this->registry->execute('auth.session.revoke',
                        ['_identity' => ['token_hash' => $hash]]);
                    $revoked = (bool) ($out['revoked'] ?? false);
                } catch (\Throwable $e) {
                    $this->kernel->trace('会话撤销走接口失败，降级直撤', ['error' => $e->getMessage()]);
                    $revoked = $this->sessions !== null ? $this->sessions->revoke($hash) : false;
                }
            } elseif ($this->sessions !== null) {
                $revoked = $this->sessions->revoke($hash);
            }
        }
        $response->setHeader('Set-Cookie', SessionCookie::clearHeader());
        return ['revoked' => $revoked];
    }

    /**
     * 换号登录前的残留登出：浏览器还带着上一个账号的会话 Cookie 时，
     * 先把它指向的令牌撤销再签发新会话，避免旧令牌在库里继续有效。
     *
     * 走 auth.logout（通用撤销）：残留 Cookie 指向的令牌无论 kind 是什么，
     * 都是「这个浏览器不再需要的凭据」。revoked=false 是幂等结果（令牌本来
     * 就失效了），不是故障。失败不阻断登录 —— 新会话的 Cookie 会覆盖旧值。
     */
    public function revokeResidual(Request $request): bool
    {
        $token = SessionCookie::read($request);
        if ($token === '' || $this->registry === null) return false;
        try {
            $out = $this->registry->execute('auth.logout',
                ['_identity' => ['token_hash' => hash('sha256', $token)]]);
            return (bool) ($out['revoked'] ?? false);
        } catch (\Throwable $e) {
            $this->kernel->trace('残留凭据撤销不可用', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * 页面登录的独立限流桶：账号锁定挡的是「猜某一个账号」，这里挡的是
     * 「同一个来源轮着猜很多账号」。限流器不可用时放行但留痕，不把登录整站打死；
     * 账号自身的失败计数与锁定仍然生效，不会因此变成无限尝试。
     */
    private function throttle(string $ip): void
    {
        if ($ip === '') return;
        try {
            $limiter = new RateLimiter(
                $this->kernel->root() . '/storage',
                (int) $this->kernel->config()->get('user.login.page_max', 10),
                (int) $this->kernel->config()->get('user.login.page_window', 300)
            );
        } catch (\Throwable $e) {
            $this->kernel->trace('页面登录限流不可用', ['error' => $e->getMessage()]);
            return;
        }
        $hit = $limiter->check('page-login|' . $ip);
        if ($hit['degraded']) {
            $this->kernel->trace('页面登录限流降级', ['ip' => $ip]);
            return;
        }
        if (!$hit['allowed']) {
            throw new ApiOperationException(
                '登录尝试过于频繁，请在 ' . (int) $hit['retry_after'] . ' 秒后重试', 42901
            );
        }
    }

    /** 供页面把业务码翻成 HTTP 状态；未登记的 4xxxx 一律按请求问题处理。 */
    public static function statusOf(int $code): int
    {
        $status = Envelope::statusOf($code);
        return $status === 500 && $code >= 40000 && $code < 50000 ? 422 : $status;
    }
}
