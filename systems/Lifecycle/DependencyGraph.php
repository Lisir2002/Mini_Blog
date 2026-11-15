<?php
declare(strict_types=1);
namespace Wx\Systems\Lifecycle;

use Wx\Core\HookBus;

/**
 * 依赖图协作者 —— 只判断「装得上吗 / 卸得掉吗 / 会不会成环」。
 *
 * v0.67 A3 从「身份存在性检查」升级为真正的依赖图：
 *   - 身份：按清单 slug / name 匹配，不用目录名；
 *   - 状态：目录里有清单还不够，必须在装卸台账登记且未被卸载（堵住丢假清单的 fail-open）；
 *   - 版本：requires 支持 identity@约束（>= / ^ / ~ / 区间 / 精确），纯身份仍兼容；
 *   - 成环：把待装包并入全图后从自身出发 DFS，遇到回边即拒。
 * 批次装载顺序由 core/PackageLoader 决定（批内拓扑，忽略图外依赖），
 * 本协作者只做准入裁决，不排顺序。
 */
final class DependencyGraph
{
    public function __construct(
        private string $root,
        private UnitCatalog $catalog,
        private HookBus $hooks
    ) {}

    public function role(): string { return 'dependency_graph'; }

    /** @param array<string, mixed> $manifest @param string|null $self 待装包身份，用于环检测 */
    public function assertSatisfied(array $manifest, ?string $self = null): void
    {
        $this->hooks->emit('lifecycle.collab', $this->role(), 'assert_satisfied');
        $index = [];
        foreach ($this->catalog->allManifests() as $meta) {
            $index[(string) $meta['identity']] = $meta;
        }
        // 先判结构：成环比「某个依赖不存在」更根本，也避免半装状态下留下环。
        if ($self !== null && $self !== '') $this->assertAcyclic($manifest, $self, $index);
        foreach ((array) ($manifest['requires'] ?? []) as $dep) {
            [$identity, $constraint] = self::parse((string) $dep);
            $meta = $index[$identity] ?? null;
            if ($meta === null) throw new LifecycleException('依赖未满足：' . $identity, 40901);
            if ((string) ($meta['state'] ?? 'absent') === 'absent') {
                throw new LifecycleException('依赖未登记或已卸载：' . $identity, 40901);
            }
            $have = (string) ($meta['version'] ?? '0.0.0');
            if ($constraint !== '' && !self::satisfies($have, $constraint)) {
                throw new LifecycleException(
                    "依赖版本不满足：{$identity}@{$constraint}（实际 {$have}）", 40901
                );
            }
        }
    }

    public function assertNoReverse(string $type, string $slug): void
    {
        $this->hooks->emit('lifecycle.collab', $this->role(), 'assert_no_reverse');
        foreach ($this->catalog->allManifests() as $meta) {
            if ($meta['identity'] === $slug) continue;
            foreach ((array) ($meta['requires'] ?? []) as $dep) {
                if (self::parse((string) $dep)[0] === $slug) {
                    throw new LifecycleException('无法卸载：仍被 ' . $meta['identity'] . ' 依赖', 40901);
                }
            }
        }
        if ($type === 'theme' && is_file($this->root . '/config/app.php')) {
            $cfg = require $this->root . '/config/app.php';
            if (is_array($cfg) && ($cfg['theme']['active'] ?? null) === $slug) {
                throw new LifecycleException('无法卸载：该主题仍被配置为当前主题', 40901);
            }
        }
    }

    /** 把待装包并入全图，从它出发沿 requires 走；遇到回边即成环。 */
    private function assertAcyclic(array $manifest, string $self, array $index): void
    {
        $edges = [];
        foreach ($index as $id => $meta) {
            $edges[$id] = array_map(
                static fn($d): string => self::parse((string) $d)[0],
                (array) ($meta['requires'] ?? [])
            );
        }
        $edges[$self] = array_map(
            static fn($d): string => self::parse((string) $d)[0],
            (array) ($manifest['requires'] ?? [])
        );
        $visiting = [];
        $visit = function (string $node) use (&$visit, &$visiting, $edges): void {
            if (!isset($edges[$node])) return;
            if (isset($visiting[$node])) {
                throw new LifecycleException('依赖成环，无法确定装载顺序：' . $node, 40901);
            }
            $visiting[$node] = true;
            foreach ($edges[$node] as $dep) $visit($dep);
            unset($visiting[$node]);
        };
        $visit($self);
    }

    /** identity 或 identity@约束；纯身份表示任意版本。 */
    private static function parse(string $dep): array
    {
        $at = strpos($dep, '@');
        return $at === false ? [$dep, ''] : [substr($dep, 0, $at), substr($dep, $at + 1)];
    }

    /** 逗号分隔的多段约束全部满足才算通过；非法约束显式拒绝而不是静默放行。 */
    private static function satisfies(string $have, string $want): bool
    {
        foreach (explode(',', $want) as $part) {
            $part = trim($part);
            if ($part === '' || $part === '*') continue;
            if (!preg_match('/^(\^|~|>=|<=|!=|==|>|<|=)?\s*([0-9][0-9.]*)$/', $part, $m)) {
                throw new LifecycleException('依赖版本约束非法：' . $part, 42201);
            }
            $op = $m[1] ?: '==';
            $target = $m[2];
            $seg = explode('.', $target);
            if ($op === '^' || $op === '~') {
                $upper = $op === '^'
                    ? ((int) $seg[0] + 1) . '.0.0'
                    : $seg[0] . '.' . ((int) ($seg[1] ?? '0') + 1) . '.0';
                if (version_compare($have, $target, '<') || version_compare($have, $upper, '>=')) return false;
                continue;
            }
            $cmp = match ($op) {
                '>' => 'gt', '>=' => 'ge', '<' => 'lt', '<=' => 'le', '!=' => 'ne', default => 'eq',
            };
            if (!version_compare($have, $target, $cmp)) return false;
        }
        return true;
    }
}
