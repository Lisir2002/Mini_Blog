<?php
declare(strict_types=1);
namespace Wx\Systems\Database;

use Wx\Contracts\DatabaseInterface;
use Wx\Core\AbstractSystem;
use Wx\Core\Kernel;

/**
 * 第 8 个系统层 —— 用来演示「保留可接入新系统层的能力」。
 *
 * 它没有写进用户最初的系统清单，但接入方式与 Log / User 等完全对等：
 * 一个目录 + 一份 system.json，核心与其它系统层零改动。
 * MySQL 属于技术栈，但不属于核心：SQL 与表结构全部留在这里及各业务系统层。
 */
final class DatabaseSystem extends AbstractSystem
{
    public function name(): string { return 'database'; }

    public function register(Kernel $kernel): void
    {
        $kernel->container()->singleton(
            DatabaseInterface::class,
            static function () use ($kernel): PdoDatabase {
                return new PdoDatabase(
                    (string) $kernel->config()->get('database.dsn', ''),
                    (string) $kernel->config()->get('database.user', ''),
                    (string) $kernel->config()->get('database.pass', ''),
                    (string) $kernel->config()->get('database.prefix', 'wx_')
                );
            },
            'system:database',
            10
        );
    }
}
