<?php
declare(strict_types=1);
namespace Wx\Systems\User;

use Wx\Contracts\UserProviderInterface;

/**
 * 会话版用户提供者（v0.1 最小实现）。
 * 生产环境应换成基于 DatabaseInterface 的 MySQL 实现 —— 只需换一行绑定，核心与主题零改动。
 */
final class SessionUserProvider implements UserProviderInterface
{
    public function current(): ?array
    {
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['wx_user'])) {
            return (array) $_SESSION['wx_user'];
        }
        return null;
    }

    public function find(int|string $id): ?array
    {
        return null;
    }

    public function count(): int
    {
        return $this->current() === null ? 0 : 1;
    }
}
