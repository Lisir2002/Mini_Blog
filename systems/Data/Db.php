<?php
declare(strict_types=1);
namespace Wx\Systems\Data;

use Throwable;
use Wx\Contracts\DatabaseInterface;
use Wx\Core\Kernel;

/**
 * 数据库系统层内部工具：惰性探测 MySQL 是否可用。
 *
 * 能力型系统层据此在「PDO MySQL 实现」与「内存 / 会话适配器」之间做选择，
 * 从而实现「接上库即用 MySQL，拔掉库也能启动」——这正是契约与实现可分离的兑现。
 * 探测逻辑属于系统层，核心完全不知道它的存在。
 */
final class Db
{
    public static function connected(Kernel $kernel): bool
    {
        if (!$kernel->container()->has(DatabaseInterface::class)) return false;
        try {
            $kernel->container()->resolve(DatabaseInterface::class)->pdo();
            return true;
        } catch (Throwable $e) {
            if ($kernel->config()->get('database.auto_fallback', true) === false) {
                throw $e;
            }
            return false;
        }
    }

    public static function optional(Kernel $kernel): ?DatabaseInterface
    {
        return self::connected($kernel)
            ? $kernel->container()->resolve(DatabaseInterface::class)
            : null;
    }
}
