<?php
declare(strict_types=1);
namespace Wx\Systems\Maintenance;

use Wx\Core\AbstractSystem;
use Wx\Core\Kernel;

/**
 * 第 19 个系统层 —— 周期维护的统一入口。
 *
 * 本层不认识任何业务表，也不替别人写清理 SQL。它只提供三件能力：
 *   1. 任务注册表 + 台账（MaintenanceRegistry）；
 *   2. maintenance.tasks 过滤器：各归属层自报任务，删掉本层业务语义不变；
 *   3. 双模式触发：CLI 显式跑（唯一会写清理的路径），或请求末尾搭车跑 light 任务。
 *
 * 两个刻意的设计边界：
 *   - 任务收集挂在 kernel.ready（Kernel::boot 末尾），而不是本层 boot()。
 *     本层 priority 90 虽排最后，但「排最后」是拓扑排序的实现细节，不是契约；
 *     kernel.ready 才是「所有系统层 boot 都已完成」的显式语义。
 *   - 搭车默认关闭（maintenance.piggyback=false）。框架不悄悄改变既有写入行为，
 *     与 comment.throttle / performance.enabled 的惯例一致。开启后也只跑 light 任务，
 *     扫表删行这类重活永远只能由 CLI 触发。
 */
final class MaintenanceSystem extends AbstractSystem
{
    public function name(): string { return 'maintenance'; }

    public function register(Kernel $kernel): void
    {
        $kernel->container()->singleton(
            MaintenanceRegistry::class,
            static fn(): MaintenanceRegistry => new MaintenanceRegistry(
                $kernel->root(), (array) $kernel->config()->get('maintenance', [])
            ),
            'system:maintenance',
            10
        );
    }

    public function boot(Kernel $kernel): void
    {
        /** @var MaintenanceRegistry $registry */
        $registry = $kernel->container()->resolve(MaintenanceRegistry::class);
        $options  = (array) $kernel->config()->get('maintenance', []);

        foreach (BuiltinTasks::all($kernel->root(), $options) as $task) {
            $registry->register($task);
        }

        $kernel->hooks()->on('kernel.ready', static function () use ($registry, $kernel): void {
            foreach ((array) $kernel->hooks()->apply('maintenance.tasks', [], $kernel) as $task) {
                if ($task instanceof MaintenanceTask) $registry->register($task);
            }
        }, 10);

        $kernel->hooks()->on('kernel.shutdown', static function () use ($registry, $kernel): void {
            if ($kernel->config()->get('maintenance.enabled', true) !== true) return;
            if ($kernel->config()->get('maintenance.piggyback', false) !== true) return;
            $registry->run('piggyback', null, false, time());
        }, 10);
    }
}
