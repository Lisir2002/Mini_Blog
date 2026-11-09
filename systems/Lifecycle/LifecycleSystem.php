<?php
declare(strict_types=1);
namespace Wx\Systems\Lifecycle;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Contracts\LifecycleManagerInterface;
use Wx\Core\AbstractSystem;
use Wx\Core\Kernel;

/**
 * 装卸系统 —— 元系统层（meta layer）。
 *
 * 它不产出内容、不提供业务数据，只管理其余所有系统层与承载包的生命周期。
 * 自身由内核在装配期直接实例化，不可被卸载；站点程序（core）的升级同样由它执行，
 * 但默认关闭并需显式开启。
 *
 * 与接口系统的关系：装卸系统把每个操作登记为规范化接口；接口系统不认识装卸系统，
 * 只认 operation 名。任一系统被停用，另一方仍可独立工作。
 */
final class LifecycleSystem extends AbstractSystem
{
    public function name(): string { return 'lifecycle'; }

    public function register(Kernel $kernel): void
    {
        $manager = new LifecycleManager(
            $kernel->root(),
            $kernel->hooks(),
            (array) $kernel->config()->get('lifecycle', [])
        );

        $kernel->container()->singleton(
            LifecycleManagerInterface::class,
            static fn(): LifecycleManager => $manager,
            'system:lifecycle',
            10
        );

        // 装载权的统一裁决点：停用（installed）的包不进入装配
        $kernel->hooks()->filter('package.enabled', static function (
            bool $enabled, string $slug, string $manifest, Kernel $k
        ) use ($manager): bool {
            $type = match ($manifest) {
                'theme.json'  => 'theme',
                'plugin.json' => 'plugin',
                'page.json'   => 'page',
                default       => null,
            };
            if ($type === null) return $enabled;
            $state = $manager->stateOf($type, $slug);
            // 未登记（absent）兼容旧包：默认启用；已登记则仅 active 可装载
            return $state === 'absent' ? $enabled : $state === 'active';
        }, 10);
    }

    public function boot(Kernel $kernel): void
    {
        if (!$kernel->container()->has(ApiRegistryInterface::class)) {
            $kernel->trace('接口系统未启用，装卸操作仅通过契约调用');
            return;
        }
        /** @var ApiRegistryInterface $registry */
        $registry = $kernel->container()->resolve(ApiRegistryInterface::class);
        /** @var LifecycleManagerInterface $m */
        $m = $kernel->container()->resolve(LifecycleManagerInterface::class);

        $registry->registerOperation('lifecycle.units', static fn(array $in): array => [
            'units' => $m->units(), 'total' => count($m->units()),
        ]);
        $registry->registerOperation('lifecycle.install', static function (array $in) use ($m): array {
            $options = [];
            if (isset($in['slug']) && $in['slug'] !== '') $options['slug'] = (string) $in['slug'];
            return $m->install((string) $in['type'], (string) $in['source'], $options);
        });
        $registry->registerOperation('lifecycle.activate',
            static fn(array $in): array => $m->activate((string) $in['type'], (string) $in['slug']));
        $registry->registerOperation('lifecycle.deactivate',
            static fn(array $in): array => $m->deactivate((string) $in['type'], (string) $in['slug']));
        $registry->registerOperation('lifecycle.upgrade',
            static fn(array $in): array => $m->upgrade((string) $in['type'], (string) $in['slug'], (string) $in['source']));
        $registry->registerOperation('lifecycle.uninstall',
            static fn(array $in): array => $m->uninstall((string) $in['type'], (string) $in['slug']));
        $registry->registerOperation('lifecycle.rollback', static function (array $in) use ($m): array {
            $version = isset($in['version']) && $in['version'] !== '' ? (string) $in['version'] : null;
            return $m->rollback((string) $in['type'], (string) $in['slug'], $version);
        });
        $registry->registerOperation('lifecycle.releases', static fn(array $in): array => [
            'releases' => $m->releases((string) $in['type'], (string) $in['slug']),
        ]);
        $registry->registerOperation('lifecycle.verify',
            static fn(array $in): array => $m->verify((string) $in['type'], (string) $in['slug']));
    }
}
