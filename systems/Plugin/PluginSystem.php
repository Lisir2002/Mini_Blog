<?php
declare(strict_types=1);
namespace Wx\Systems\Plugin;

use Wx\Core\AbstractSystem;
use Wx\Core\Kernel;
use Wx\Core\PackageLoader;

/**
 * 插件系统 —— 三大承载系统之一。
 * 装载全部（或白名单）插件包；插件可订阅钩子、覆盖契约、注册路由、追加独立页。
 */
final class PluginSystem extends AbstractSystem
{
    public function name(): string { return 'plugin'; }

    public function boot(Kernel $kernel): void
    {
        $only = (array) $kernel->config()->get('plugin.enabled', []);
        $loader = new PackageLoader($kernel, $kernel->root() . '/plugins', 'plugin.json');
        foreach ($loader->load($only === [] ? null : $only) as $slug => $package) {
            $package['instance']->register($kernel);
            $kernel->hooks()->emit('plugin.loaded', $slug, $kernel);
        }
    }
}
