<?php
declare(strict_types=1);
namespace Wx\Systems\Security;

use Wx\Contracts\ApiAuthenticatorInterface;
use Wx\Contracts\SecurityGuardInterface;
use Wx\Core\AbstractSystem;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;
use Wx\Systems\Data\Db;

/**
 * 安全系统：提供防护契约与鉴权契约，通过钩子注入 HTTP 安全头（不涉及任何样式）。
 * 接上 MySQL 时令牌 / CSRF / 页面会话落库并带过期；拔掉库时优雅回退到进程内实现。
 *
 * 本类只做装配：防护实现归 DefaultGuard，令牌实现归 DbApiAuthenticator，
 * 会话编排归 SessionBootstrap，凭据载体归 SessionCookie。
 */
final class SecuritySystem extends AbstractSystem
{
    public function name(): string { return 'security'; }

    public function register(Kernel $kernel): void
    {
        $kernel->container()->singleton(
            SecurityGuardInterface::class,
            static fn(): DefaultGuard => new DefaultGuard(Db::optional($kernel)),
            'system:security',
            10
        );
        $kernel->container()->singleton(
            ApiAuthenticatorInterface::class,
            static fn(): DbApiAuthenticator => new DbApiAuthenticator(
                Db::optional($kernel),
                (array) $kernel->config()->get('api.tokens', [])
            ),
            'system:security',
            10
        );

        $kernel->hooks()->on('kernel.response', static function ($request, Response $response, Kernel $k): void {
            $response
                ->setHeader('X-Content-Type-Options', 'nosniff')
                ->setHeader('X-Frame-Options', 'SAMEORIGIN')
                ->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
            $csp = (string) $k->config()->get('security.csp', '');
            if ($csp !== '') $response->setHeader('Content-Security-Policy', $csp);
        }, 10);

        // 页面会话的凭据载体：只把令牌变成 HttpOnly Cookie，不参与任何业务判断
        (new SessionCookie($kernel))->install();
    }

    public function boot(Kernel $kernel): void
    {
        /** @var DefaultGuard $guard */
        $guard = $kernel->container()->resolve(SecurityGuardInterface::class);
        $guard->ensureSchema();
        /** @var DbApiAuthenticator $auth */
        $auth = $kernel->container()->resolve(ApiAuthenticatorInterface::class);
        $auth->ensureSchema();

        (new SessionBootstrap($kernel))->boot();
    }
}
