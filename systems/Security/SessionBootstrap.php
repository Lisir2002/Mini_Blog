<?php
declare(strict_types=1);
namespace Wx\Systems\Security;

use Wx\Contracts\ApiAuthenticatorInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Contracts\UserProviderInterface;
use Wx\Core\Kernel;
use Wx\Systems\Data\Db;

/**
 * 页面会话的装配器 —— 从 SecuritySystem 拆出的单一职责协作者（规矩 H）。
 *
 * 只做三件事：确认令牌实现可落库、按配置构造 SessionManager、把 3 个会话接口
 * 交给 api 系统登记。控制器不在这里，凭据载体也不在这里（见 SessionCookie）。
 */
final class SessionBootstrap
{
    public function __construct(private Kernel $kernel) {}

    public function role(): string { return 'session_bootstrap'; }

    public function boot(): void
    {
        $auth = $this->kernel->container()->resolve(ApiAuthenticatorInterface::class);
        if (!$auth instanceof DbApiAuthenticator) return;

        $db = Db::optional($this->kernel);
        if ($db === null) return;

        $container = $this->kernel->container();
        if (!$container->has(ApiRegistryInterface::class)) return;

        $sessions = new SessionManager(
            $db, $auth, (int) $this->kernel->config()->get('security.session.ttl', 7200)
        );
        $users = $container->has(UserProviderInterface::class)
            ? $container->resolve(UserProviderInterface::class) : null;
        (new SessionOperations($sessions, $users, $this->kernel))->register(
            $container->resolve(ApiRegistryInterface::class)
        );
    }
}
