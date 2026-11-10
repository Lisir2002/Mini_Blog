<?php
declare(strict_types=1);
namespace Wx\Systems\Data;

use Wx\Contracts\DatabaseInterface;
use Wx\Core\AbstractSystem;
use Wx\Core\Kernel;

/**
 * 第 8 个系统层 —— v0.14 起从「连接层」升级为「数据层」。
 *
 * 为什么改名：这个层原本只提供 pdo/query/exec，名字叫 database 却既不负责数据一致性，
 * 也不负责效率与完整性。升级后它管四件事：连接与严格模式、事务会话、查询观测、孤儿巡检。
 * 它仍然不认识任何业务表——关系由归属系统自己登记，数据层只执行。
 */
final class DataSystem extends AbstractSystem
{
    public function name(): string { return 'data'; }

    public function register(Kernel $kernel): void
    {
        $container = $kernel->container();
        $owner = 'system:data';

        $container->singleton(
            QueryProfilerInterface::class,
            static fn(): QueryProfiler => new QueryProfiler(),
            $owner,
            10
        );

        $container->singleton(
            DatabaseInterface::class,
            static function () use ($kernel, $container): PdoDatabase {
                return new PdoDatabase(
                    (string) $kernel->config()->get('database.dsn', ''),
                    (string) $kernel->config()->get('database.user', ''),
                    (string) $kernel->config()->get('database.pass', ''),
                    (string) $kernel->config()->get('database.prefix', 'wx_'),
                    $container->resolve(QueryProfilerInterface::class),
                    (int) $kernel->config()->get('database.timeout.connect', 2)
                );
            },
            $owner,
            10
        );

        $container->singleton(RelationRegistry::class, static fn(): RelationRegistry => new RelationRegistry(), $owner, 10);

        $container->singleton(
            DataSessionInterface::class,
            static fn(): DataSession => new DataSession($container->resolve(DatabaseInterface::class)),
            $owner,
            10
        );

        $container->singleton(
            IntegrityCheckerInterface::class,
            static fn(): IntegrityChecker => new IntegrityChecker(
                $container->resolve(DatabaseInterface::class),
                $container->resolve(RelationRegistry::class)
            ),
            $owner,
            10
        );
    }
}
