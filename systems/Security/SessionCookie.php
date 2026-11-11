<?php
declare(strict_types=1);
namespace Wx\Systems\Security;

use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;

/**
 * 会话凭据的载体 —— 把执行者返回的令牌变成 HttpOnly Cookie，并从响应体移除明文。
 *
 * 为什么必须在安全层做：令牌怎么存、怎么带、脚本能不能读到，都是安全属性，
 * 不属于业务执行者。执行者只负责「这个人凭据正确，给他一个会话」。
 *
 * 只对 `POST/DELETE {api.prefix}/auth/session` 生效；其它响应原样返回。
 * 明文不进响应体，XSS 也偷不到会话令牌；Cookie 只用于页面 GET（见 PageAccessGuard），
 * API 写操作仍要求 Authorization 头，因此不存在 Cookie 自动携带造成的 CSRF 面。
 */
final class SessionCookie
{
    public function __construct(private Kernel $kernel) {}

    public function role(): string { return 'session_cookie'; }

    public function install(): void
    {
        $this->kernel->hooks()->filter('route.response', function (
            Response $response, Request $request
        ): Response {
            $prefix = (string) $this->kernel->config()->get('api.prefix', '/api/v1');
            if ($request->path() !== $prefix . '/auth/session') return $response;

            if ($request->method() === 'POST') {
                $decoded = json_decode($response->body(), true);
                if (!is_array($decoded) || ($decoded['code'] ?? null) !== 0) return $response;
                $token = (string) ($decoded['data']['token'] ?? '');
                if ($token === '') return $response;

                unset($decoded['data']['token']);
                $maxAge = (int) ($decoded['data']['max_age']
                    ?? $this->kernel->config()->get('security.session.ttl', 7200));
                $json = json_encode(
                    $decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
                );
                $response
                    ->setBody($json === false ? '{}' : $json)
                    ->setHeader('Set-Cookie', self::header($token, $maxAge));
                return $response;
            }
            if ($request->method() === 'DELETE') {
                $response->setHeader('Set-Cookie', self::clearHeader());
            }
            return $response;
        }, 40);
    }

    /** 会话 Cookie 的唯一写法：HttpOnly + SameSite=Lax + 全站路径。 */
    public static function header(string $token, int $maxAge): string
    {
        return 'wx_session=' . rawurlencode($token)
            . '; Path=/; HttpOnly; SameSite=Lax; Max-Age=' . max(0, $maxAge);
    }

    public static function clearHeader(): string
    {
        return 'wx_session=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0';
    }

    /**
     * 只读 wx_session，不认 wx_token：页面会话与 API 令牌是两种凭据，
     * 混读会让「登出」把一个脚本令牌误当成会话撤销。
     */
    public static function read(Request $request): string
    {
        $cookie = (string) ($request->header('Cookie') ?? '');
        if ($cookie === '') return '';
        foreach (explode(';', $cookie) as $piece) {
            $pair = explode('=', trim($piece), 2);
            if (count($pair) !== 2 || $pair[0] !== 'wx_session') continue;
            return rawurldecode(trim($pair[1]));
        }
        return '';
    }
}
