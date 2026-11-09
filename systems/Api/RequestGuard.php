<?php
declare(strict_types=1);
namespace Wx\Systems\Api;

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiAuthenticatorInterface;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;

/**
 * 请求守卫协作者 —— 在业务执行者之前拦下不该放行的请求。
 *
 * 顺序是刻意设计的：限流 → 写接口公网边界 → 鉴权 → 参数校验。
 * 鉴权先于参数细节（未认证者不应探知参数要求），429 与校验失败都不写幂等缓存。
 * 身份以 _identity 注入 input，业务执行者无需自己解析请求头。
 */
final class RequestGuard
{
    private RateLimiter $limiter;

    public function __construct(private Kernel $kernel)
    {
        $root = $kernel->root();
        $this->limiter = new RateLimiter(
            $root,
            (int) $kernel->config()->get('api.rate_limit.max', 120),
            (int) $kernel->config()->get('api.rate_limit.window', 60)
        );
    }

    public function role(): string { return 'request_guard'; }

    /**
     * @param array<string, mixed> $endpoint
     * @return array{reject: bool, status: int, body: array<string, mixed>,
     *               headers: array<string, string>, input: array<string, mixed>, is_write: bool}
     */
    public function check(Request $request, array $endpoint, string $requestId): array
    {
        $headers = [];
        $isWrite = !in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);

        if ($this->kernel->config()->get('api.rate_limit.enabled', true) === true) {
            $subject = $request->clientIp() ?? ('cli-' . getmypid());
            $r = $this->limiter->check($subject);
            $headers['X-RateLimit-Limit']     = (string) $r['limit'];
            $headers['X-RateLimit-Remaining'] = (string) $r['remaining'];
            $headers['X-RateLimit-Reset']     = (string) $r['reset'];
            if (!$r['allowed']) {
                $headers['Retry-After'] = (string) $r['retry_after'];
                return $this->reject(429, Envelope::error(42901, $requestId), $headers, [], $isWrite);
            }
        }

        // 写接口公网边界：默认仅本机来源可调用。认证类端点（登录 / 建会话）必须显式声明
        // public_write=true 才能被公网访问 —— 边界是默认拒绝，例外写在清单里，不藏在代码里。
        if ($isWrite && ($endpoint['public_write'] ?? false) !== true && !$request->isLocal()
            && $this->kernel->config()->get('api.expose_write', false) !== true) {
            return $this->reject(403, Envelope::error(40302, $requestId), $headers, [], $isWrite);
        }

        $input    = array_merge($request->query(), $request->body(), $request->json());
        $level    = (string) ($endpoint['auth'] ?? 'public');
        $auth     = $this->authenticator();
        $acceptSession = ($endpoint['accept_session'] ?? false) === true;
        $token    = $this->bearer($request);
        // 只有「会话自身的管理端点」接受 Cookie 里的会话令牌（登出、查询当前会话）。
        // 它们必须能被浏览器直接调用，而 HttpOnly 令牌脚本读不到，只能靠 Cookie。
        if ($token === '' && $acceptSession) $token = $this->sessionCookie($request);
        $identity = null;
        if ($level !== '' && $level !== 'public') {
            if ($auth === null || $token === '') {
                return $this->reject(401, Envelope::error(40101, $requestId), $headers, [], $isWrite);
            }
            $identity = $auth->authenticate($token);
            if ($identity === null) {
                return $this->reject(401, Envelope::error(40102, $requestId), $headers, [], $isWrite);
            }
            // 页面会话令牌只服务浏览器页面与会话自身管理：其余接口一律不接受，
            // 避免「一次登录」变成可调任意写接口的万能凭据。
            if (($identity['kind'] ?? '') === 'session' && !$acceptSession) {
                return $this->reject(401, Envelope::error(40102, $requestId), $headers, [], $isWrite);
            }
            if (!$auth->allows($level, $identity)) {
                return $this->reject(403, Envelope::error(40301, $requestId), $headers, [], $isWrite);
            }
        } elseif ($auth !== null && $token !== '') {
            // public 接口可选携带身份：不强制认证，但认证成功则注入，
            // 让「作者可见自己的草稿」「按角色差异化数据」这类客体权限得以生效。
            $identity = $auth->authenticate($token);
            // public 接口同样不接受会话令牌：页面会话的身份边界不延伸到业务接口
            if (($identity['kind'] ?? '') === 'session' && !$acceptSession) $identity = null;
        }
        $input['_identity'] = $identity;

        // 细粒度授权：auth 只保证「你是谁」，permission 决定「你能不能做这件事」。
        // 权限层缺席 / 未声明 / abstain 一律拒绝（fail-closed），不探知参数细节。
        $permission = (string) ($endpoint['permission'] ?? '');
        if ($permission !== '') {
            $acl = $this->accessControl();
            if ($acl === null || !$acl->allows($identity, $permission)) {
                return $this->reject(403, Envelope::error(40303, $requestId), $headers, [], $isWrite);
            }
        }

        $errors = $this->validateParams($endpoint, $input);
        if ($errors !== []) {
            return $this->reject(422, Envelope::error(42201, $requestId, $errors), $headers, [], $isWrite);
        }

        return ['reject' => false, 'status' => 200, 'body' => [], 'headers' => $headers,
            'input' => $input, 'is_write' => $isWrite];
    }

    /** @param array<string, string> $headers @param array<string, mixed> $body */
    private function reject(int $status, array $body, array $headers, array $input, bool $isWrite): array
    {
        return ['reject' => true, 'status' => $status, 'body' => $body, 'headers' => $headers,
            'input' => $input, 'is_write' => $isWrite];
    }

    private function authenticator(): ?ApiAuthenticatorInterface
    {
        $container = $this->kernel->container();
        return $container->has(ApiAuthenticatorInterface::class)
            ? $container->resolve(ApiAuthenticatorInterface::class)
            : null;
    }

    private function accessControl(): ?AccessControlInterface
    {
        $container = $this->kernel->container();
        return $container->has(AccessControlInterface::class)
            ? $container->resolve(AccessControlInterface::class)
            : null;
    }

    private function sessionCookie(Request $request): string
    {
        if (!in_array($request->method(), ['GET', 'HEAD', 'DELETE', 'POST'], true)) return '';
        $cookie = (string) ($request->header('Cookie') ?? '');
        if ($cookie === '') return '';
        if (preg_match('/(?:^|;\s*)wx_session=([^;\s]+)/', $cookie, $m)) {
            return rawurldecode((string) $m[1]);
        }
        return '';
    }

    private function bearer(Request $request): string
    {
        $auth = (string) ($request->header('Authorization') ?? '');
        if (stripos($auth, 'Bearer ') === 0) return trim(substr($auth, 7));
        return trim((string) ($request->header('X-Api-Token') ?? ''));
    }

    /** @param array<string, mixed> $endpoint @param array<string, mixed> $input
     *  @return list<array<string, string>> */
    private function validateParams(array $endpoint, array $input): array
    {
        $errors = [];
        foreach ((array) ($endpoint['params'] ?? []) as $param) {
            $name    = (string) $param['name'];
            $present = array_key_exists($name, $input) && $input[$name] !== '';
            if (($param['required'] ?? false) && !$present) {
                $errors[] = ['code' => 'required', 'field' => $name, 'message' => '缺少必填参数：' . $name];
                continue;
            }
            if (!$present) continue;
            $type  = (string) ($param['type'] ?? 'string');
            $value = $input[$name];
            $valid = match ($type) {
                'int'   => is_numeric($value),
                'bool'  => is_bool($value) || in_array((string) $value, ['0', '1', 'true', 'false'], true),
                'array' => is_array($value),
                default => is_scalar($value),
            };
            if (!$valid) {
                $errors[] = ['code' => 'invalid_type', 'field' => $name, 'message' => "参数类型应为 {$type}：{$name}"];
            }
        }
        return $errors;
    }
}
