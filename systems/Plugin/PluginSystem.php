<?php
declare(strict_types=1);
namespace Wx\Systems\Plugin;

use Wx\Core\AbstractSystem;
use Wx\Core\Kernel;
use Wx\Core\PackageLoader;

/**
 * 插件系统 —— 三大承载系统之一。
 * 装载插件包：插件可订阅钩子、覆盖契约、注册路由、追加独立页。
 *
 * 装配唯一真源是装卸系统的状态机（v0.61）：目录下全部插件包都会被枚举，
 * 能否 register 由 package.enabled 钩子裁决——仅 lifecycle 台账里的 active 放行，
 * absent（未登记）一律拒绝（v0.59 关闭「丢文件即执行」的 fail-open 面）。
 * 不再有 plugin.enabled 配置白名单这第二真相：不想装载的插件走 lifecycle.deactivate。
 */
final class PluginSystem extends AbstractSystem
{
    public function name(): string { return 'plugin'; }

    public function boot(Kernel $kernel): void
    {
        $loader = new PackageLoader($kernel, $kernel->root() . '/plugins', 'plugin.json');
        foreach ($loader->load(null) as $slug => $package) {
            $package['instance']->register($kernel);
            $kernel->hooks()->emit('plugin.loaded', $slug, $kernel);
        }
    }
}
