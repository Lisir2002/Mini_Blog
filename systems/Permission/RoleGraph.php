<?php
declare(strict_types=1);
namespace Wx\Systems\Permission;

/**
 * 角色继承图 —— 把「谁继承谁」展开成有效许可集合。
 *
 * 单一职权：只处理图结构；读写交给 PdoPermissionStore，缓存交给 PermissionCache，
 * 决策交给 AccessController。三类职权各自独立，可单独替换或验证。
 *
 * 写入前裁决：自环、成环、深度超限一律拒绝，绝不把脏关系写进库。
 * 展开时仍有 visited 兜底，即使有人手工改库造出环也不会死循环。
 *
 * 继承语义是「许可并集」：子角色继承父角色的全部许可；
 * 在子角色上 revoke 不会撤销父角色的许可，要收回必须改父角色。
 * 显式 deny（资源 ACL）与策略钩子的收紧优先级不变。
 */
final class RoleGraph
{
    public const MAX_DEPTH = 8;

    /** @var list<string> 累积的展开告警 */
    private array $warnings = [];

    public function __construct(
        private PdoPermissionStore $store,
        private PermissionCache $cache
    ) {}

    public function role(): string { return 'role_graph'; }

    /** @return list<string> */
    public function warnings(): array { return $this->warnings; }

    /** @return list<string> 直接父角色 */
    public function parentsFor(string $role): array
    {
        return $this->cache->remember(
            'parents:' . $role,
            fn(): array => $this->store->parentsFor($role)
        );
    }

    /** @return list<string> 全部祖先（不含自身），广度优先、去重、带深度保护 */
    public function ancestors(string $role): array
    {
        return $this->cache->remember('ancestors:' . $role, fn(): array => $this->expand($role));
    }

    /** @return list<string> 自身 + 全部祖先，自身优先 */
    public function chain(string $role): array
    {
        return array_merge([$role], $this->ancestors($role));
    }

    /** @return list<string> 去重排序后的有效许可（自身与全部祖先的并集） */
    public function effectivePermissions(string $role): array
    {
        $out = [];
        foreach ($this->chain($role) as $r) {
            $grants = $this->cache->remember('grants:' . $r, fn(): array => $this->store->grantsFor($r));
            foreach ($grants as $perm) $out[(string) $perm] = true;
        }
        $perms = array_keys($out);
        sort($perms);
        return $perms;
    }

    /** 写入前裁决：自环 / 成环 / 深度超限一律拒绝。 */
    public function canSetParent(string $role, string $parent): array
    {
        if ($role === '' || $parent === '') return ['ok' => false, 'reason' => 'empty'];
        if ($role === $parent) return ['ok' => false, 'reason' => 'self'];
        $upstream = $this->expand($parent);
        if (in_array($role, $upstream, true)) return ['ok' => false, 'reason' => 'cycle'];
        if (count($upstream) >= self::MAX_DEPTH) return ['ok' => false, 'reason' => 'depth'];
        return ['ok' => true, 'reason' => 'ok'];
    }

    /** @return array{ok: bool, reason: string, changed: bool} */
    public function setParent(string $role, string $parent): array
    {
        $verdict = $this->canSetParent($role, $parent);
        if (!$verdict['ok']) return $verdict + ['changed' => false];
        $changed = $this->store->setParent($role, $parent);
        $this->cache->flush();
        return $verdict + ['changed' => $changed];
    }

    /** @return array{ok: bool, reason: string, changed: bool} */
    public function removeParent(string $role, string $parent): array
    {
        $changed = $this->store->removeParent($role, $parent);
        $this->cache->flush();
        return ['ok' => true, 'reason' => 'ok', 'changed' => $changed];
    }

    /** 整张继承图，供 permission.role.tree 接口与守卫对账。 */
    public function tree(): array
    {
        $out = [];
        foreach (array_keys($this->store->roles()) as $role) {
            $out[$role] = [
                'role'                  => $role,
                'parents'               => $this->parentsFor($role),
                'ancestors'             => $this->ancestors($role),
                'effective_permissions' => $this->effectivePermissions($role),
            ];
        }
        return [
            'roles'     => $out,
            'max_depth' => self::MAX_DEPTH,
            'warnings'  => $this->warnings,
        ];
    }

    /** @return list<string> */
    private function expand(string $start): array
    {
        $seen  = [$start => true];
        $queue = [$start];
        $out   = [];
        $depth = 0;
        while ($queue !== [] && $depth < self::MAX_DEPTH) {
            $next = [];
            foreach ($queue as $current) {
                foreach ($this->parentsFor($current) as $parent) {
                    if (isset($seen[$parent])) continue;  // 菱形继承去重 / 残留环兜底
                    $seen[$parent] = true;
                    $out[]  = $parent;
                    $next[] = $parent;
                }
            }
            $queue = $next;
            $depth++;
        }
        if ($queue !== []) $this->warnings[] = 'depth_limit:' . self::MAX_DEPTH;
        return $out;
    }
}
