<?php
declare(strict_types=1);
namespace Wx\Systems\Page;

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiAuthenticatorInterface;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;

/**
 * 页面访问守卫 —— 与 API 的 RequestGuard 共用同一套身份与授权契约。
 *
 * 两级裁决：ApiAuthenticatorInterface::allows() 判「你是谁」，
 * AccessControlInterface::allows() 判「你能不能访问这个页面」。
 * 与 API 完全同源：同一个认证器、同一个权限决策器、同一套 fail-closed 语义。
 *
 * 拒绝时只产结构化数据 + deny 布局槽位，绝不输出 HTML / CSS / JS（规矩 J）：
 * 主题有 deny 布局则由主题渲染，没有则核心退化为纯 JSON。
 */
final class PageAccessGuard
{
    /** @var array<int, array<string, mixed>|null> 请求对象 id → 身份，同一次请求只认证一次 */
    private array $identityByRequest = [];

    public function __construct(private Kernel $kernel) {}

    public function role(): string { return 'page_access_guard'; }

    /** route.access 阶段的入口；放行原样返回，拒绝则写入 401 / 403 的结构化响应 */
    public function guard(Response $response, Request $request, PagePolicy $policy): Response
    {
        $rule   = $policy->forPath($request->path());
        $result = $this->decide($request, $rule);
        $this->identityByRequest[spl_object_id($request)] = $result['identity'];

        if ($rule !== null) {
            // 每次页面访问裁决都留痕，交由内核可观测性出口（日志系统消费）
            $this->kernel->trace('页面访问裁决', [
                'path'       => $request->path(),
                'page'       => $rule['page'],
                'auth'       => $rule['auth'],
                'permission' => $rule['permission'],
                'reason'     => $result['reason'],
                'allowed'    => $result['allowed'],
                'actor'      => (string) ($result['identity']['username'] ?? 'anonymous'),
            ]);
        }
        return $result['allowed'] ? $response : $this->deny($response, $request, $result);
    }

    /** 供 route.response 把身份注入页面数据；独立页本身不认识任何令牌 */
    public function identityFor(Request $request): ?array
    {
        return $this->identityByRequest[spl_object_id($request)] ?? null;
    }

    /** @return array{allowed:bool, identity:array<string,mixed>|null, reason:string, rule:array<string,mixed>|null} */
    private function decide(Request $request, ?array $rule): array
    {
        $identity = $this->identity($request);
        if ($rule === null) {
            // 未在页面清单声明的路径（API / 404 / 插件自有路由）不归页面系统裁决
            return ['allowed' => true, 'identity' => $identity, 'reason' => 'not_a_page', 'rule' => null];
        }

        // 1) 身份级别：复用 API 的同一个 allows()
        $level = (string) $rule['auth'];
        if ($level !== 'public') {
            if ($identity === null) return $this->no($identity, 'unauthenticated', $rule);
            $auth = $this->authenticator();
            if ($auth === null || !$auth->allows($level, $identity)) {
                return $this->no($identity, 'level_denied', $rule);
            }
        }

        // 2) 本机来源：安装向导这类敏感页不暴露公网
        if ($rule['local_only'] && !$request->isLocal()) {
            return $this->no($identity, 'local_only', $rule);
        }

        // 3) 细粒度授权：权限层缺席 / abstain 一律 fail-closed
        foreach ([(string) $rule['permission'], (string) $rule['extra_permission']] as $permission) {
            if ($permission === '') continue;
            $acl = $this->accessControl();
            if ($acl === null || !$acl->allows($identity, $permission)) {
                return $this->no($identity, 'permission_denied', $rule);
            }
        }
        return ['allowed' => true, 'identity' => $identity, 'reason' => 'allowed', 'rule' => $rule];
    }

    /** @param array<string, mixed>|null $identity @param array<string, mixed> $rule */
    private function no(?array $identity, string $reason, array $rule): array
    {
        return ['allowed' => false, 'identity' => $identity, 'reason' => $reason, 'rule' => $rule];
    }

    /** @param array{allowed:bool, identity:array<string,mixed>|null, reason:string, rule:array<string,mixed>|null} $result */
    private function deny(Response $response, Request $request, array $result): Response
    {
        $rule   = (array) $result['rule'];
        $reason = (string) $result['reason'];
        // 未认证 / 无效令牌统一 401，其余（级别不足 / 非本机 / 权限未放行）统一 403
        $status = $reason === 'unauthenticated' ? 401 : 403;
        $labels = [
            'unauthenticated'   => '该页面需要登录。请携带站点签发的 Bearer 令牌（Authorization 请求头或 wx_token Cookie）后重试。',
            'level_denied'      => '当前身份级别不足以访问该页面。',
            'local_only'        => '该页面仅允许本机来源访问。',
            'permission_denied' => '权限决策未放行（fail-closed）：缺少所需权限点。',
        ];
        $permission = (string) $rule['permission'];

        return $response
            ->setStatus($status)
            ->setLayout((string) $rule['deny_layout'])
            ->setPayload([
                'title'          => $status === 401 ? '需要登录' : '无权访问',
                'page'           => 'access_denied',
                'status'         => $status,
                'reason'         => $reason,
                'auth_level'     => (string) $rule['auth'],
                'permission'     => $permission,
                'login_endpoint' => (string) $this->kernel->config()->get('api.prefix', '/api/v1') . '/auth/token',
                'session_endpoint' => (string) $this->kernel->config()->get('api.prefix', '/api/v1') . '/auth/session',
                'return_to'      => $request->path(),
                'identity'       => $result['identity'],
                'blocks'         => [
                    ['type' => 'text', 'title' => '访问被拒绝',
                     'value' => (string) ($labels[$reason] ?? '访问被拒绝')],
                    ['type' => 'table', 'title' => '本次裁决', 'rows' => [
                        ['k' => '请求路径', 'v' => $request->path(), 'ok' => false],
                        ['k' => '要求身份', 'v' => (string) $rule['auth']],
                        ['k' => '要求权限', 'v' => $permission !== '' ? $permission : '无'],
                        ['k' => '裁决原因', 'v' => $reason],
                    ]],
                    ['type' => 'form', 'title' => '登录', 'fields' => [
                        ['tag' => 'input', 'id' => 'username', 'type' => 'text', 'placeholder' => '用户名'],
                        ['tag' => 'input', 'id' => 'password', 'type' => 'password', 'placeholder' => '口令'],
                        ['tag' => 'button', 'id' => 'login', 'label' => '签发令牌'],
                    ]],
                ],
            ]);
    }

    private function identity(Request $request): ?array
    {
        $token = $this->token($request);
        if ($token === '') return null;
        $auth = $this->authenticator();
        return $auth === null ? null : $auth->authenticate($token);
    }

    /** 身份载体与 API 同源；Cookie 只为浏览器直访后台提供便利，不引入第二套认证算法 */
    private function token(Request $request): string
    {
        $bearer = (string) ($request->header('Authorization') ?? '');
        if (stripos($bearer, 'Bearer ') === 0) return trim(substr($bearer, 7));
        $alt = trim((string) ($request->header('X-Api-Token') ?? ''));
        if ($alt !== '') return $alt;

        // Cookie 只用于页面读取：写操作必须用请求头令牌，浏览器跨站无法自动附加
        // Authorization，CSRF 面因此天然关闭（v0.9 页面会话）。
        if (!in_array($request->method(), ['GET', 'HEAD'], true)) return '';

        $cookie = (string) ($request->header('Cookie') ?? '');
        if ($cookie === '') return '';
        foreach (['wx_session', 'wx_token'] as $name) {
            if (preg_match('/(?:^|;\s*)' . $name . '=([^;\s]+)/', $cookie, $m)) {
                return rawurldecode((string) $m[1]);
            }
        }
        return '';
    }

    private function authenticator(): ?ApiAuthenticatorInterface
    {
        $c = $this->kernel->container();
        return $c->has(ApiAuthenticatorInterface::class)
            ? $c->resolve(ApiAuthenticatorInterface::class) : null;
    }

    private function accessControl(): ?AccessControlInterface
    {
        $c = $this->kernel->container();
        return $c->has(AccessControlInterface::class)
            ? $c->resolve(AccessControlInterface::class) : null;
    }
}
