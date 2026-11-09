<?php
declare(strict_types=1);
namespace Wx\Systems\Release;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Contracts\LifecycleManagerInterface;
use Wx\Core\AbstractSystem;
use Wx\Core\Kernel;
use Wx\Systems\Data\Db;
use Wx\Systems\Data\Schema;

/**
 * 版本系统 —— 站点程序的版本清单、迁移台账、健康门禁与升降级编排。
 *
 * 与装卸系统的边界：lifecycle 决定「文件怎么换」，release 决定「换到哪个版本、
 * 数据库跟着做什么、失败按什么顺序退」。两者通过 LifecycleManagerInterface 协作，
 * 不共享任何内部状态文件。
 *
 * 停用本层不影响站点运行：只是失去版本管理能力，lifecycle 的装卸仍然可用。
 */
final class ReleaseSystem extends AbstractSystem
{
    public function name(): string { return 'release'; }

    public function boot(Kernel $kernel): void
    {
        $root = $kernel->root();
        $db = Db::optional($kernel);
        if ($db !== null) {
            Schema::apply($db, __DIR__ . '/migrations');
        }
        $store = $db !== null ? new PdoMigrationStore($db) : new MemoryMigrationStore();
        $runner = new MigrationRunner($root, $db, $store, $kernel->hooks());
        if ($store->all() === []) {
            // 安装基线：只登记、不重跑。之后每次升级只执行「基线之后新增」的脚本。
            $kernel->trace('建立迁移基线', ['scripts' => $runner->baseline()]);
        }
        $manager = new ReleaseManager(
            $root,
            $kernel->container()->resolve(LifecycleManagerInterface::class),
            $runner,
            $store,
            new HealthProbe($kernel),
            new ReleaseLog($root . '/storage/releases'),
            new ReleaseUnits($root),
            (array) $kernel->config()->get('release', [])
        );

        if (!$kernel->container()->has(ApiRegistryInterface::class)) {
            $kernel->trace('接口系统未启用，版本操作仅通过 ReleaseManager 调用');
            return;
        }
        /** @var ApiRegistryInterface $registry */
        $registry = $kernel->container()->resolve(ApiRegistryInterface::class);
        (new ReleaseOperations($manager))->register($registry);
    }
}
