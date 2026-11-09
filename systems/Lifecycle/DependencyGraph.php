<?php
declare(strict_types=1);
namespace Wx\Systems\Lifecycle;

use Wx\Core\HookBus;

/**
 * 依赖图协作者 —— 只判断「装得上吗 / 卸得掉吗」。
 * 依赖按清单身份匹配，而不是按目录名；当前主题也在反向依赖的保护范围内。
 */
final class DependencyGraph
{
    public function __construct(
        private string $root,
        private UnitCatalog $catalog,
        private HookBus $hooks
    ) {}

    public function role(): string { return 'dependency_graph'; }

    /** @param array<string, mixed> $manifest */
    public function assertSatisfied(array $manifest): void
    {
        $this->hooks->emit('lifecycle.collab', $this->role(), 'assert_satisfied');
        $known = array_column($this->catalog->allManifests(), 'identity');
        foreach ((array) ($manifest['requires'] ?? []) as $dep) {
            if (!in_array((string) $dep, $known, true)) {
                throw new LifecycleException('依赖未满足：' . $dep, 40901);
            }
        }
    }

    public function assertNoReverse(string $type, string $slug): void
    {
        $this->hooks->emit('lifecycle.collab', $this->role(), 'assert_no_reverse');
        foreach ($this->catalog->allManifests() as $meta) {
            if ($meta['identity'] === $slug) continue;
            if (in_array($slug, $meta['requires'], true)) {
                throw new LifecycleException('无法卸载：仍被 ' . $meta['identity'] . ' 依赖', 40901);
            }
        }
        if ($type === 'theme' && is_file($this->root . '/config/app.php')) {
            $cfg = require $this->root . '/config/app.php';
            if (is_array($cfg) && ($cfg['theme']['active'] ?? null) === $slug) {
                throw new LifecycleException('无法卸载：该主题仍被配置为当前主题', 40901);
            }
        }
    }
}
