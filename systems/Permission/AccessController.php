<?php
declare(strict_types=1);
namespace Wx\Systems\Permission;

use Throwable;
use Wx\Contracts\AccessControlInterface;
use Wx\Core\HookBus;

/**
 * 决策核心 —— 把 RBAC（含角色继承 / 用户组）、资源 ACL（含 field 维度）、策略钩子合成一次决策。
 *
 * 优先级刻意固定：显式 deny > 显式 allow > 角色许可（主体角色 ∪ 组角色，各自展开祖先） > abstain。
 * 策略钩子 permission.decide 只能「收紧」：allow→abstain/deny 有效，
 * abstain/deny→allow 一律忽略。这样插件与主题永远无法借扩展点提权。
 *
 * v0.9 的唯一变化是「角色从哪来」：主体角色集合由 GroupResolver 给出（自身角色 + 组绑定角色），
 * 之后的裁决骨架、deny 优先、fail-closed、留痕全部保持不变。
 *
 * 本类只做编排：图结构归 RoleGraph、匹配归 AclMatcher、读写归 PdoPermissionStore、
 * 缓存归 PermissionCache、组成员归 GroupResolver。每次决策都留痕（内存 + audit 表 + 事件）。
 */
final class AccessController implements AccessControlInterface
{
    /** @var list<array<string, mixed>> */
    private array $recent = [];
    private PermissionCache $cache;
    private RoleGraph $graph;
    private AclMatcher $matcher;
    private GroupResolver $groups;

    public function __construct(
        private PdoPermissionStore $store,
        private PermissionRegistry $registry,
        private HookBus $hooks,
        bool $cacheEnabled = true,
        ?CacheBackend $cacheBackend = null
    ) {
        $this->cache   = new PermissionCache($cacheEnabled, $cacheBackend);
        $this->matcher = new AclMatcher();
        $this->graph   = new RoleGraph($store, $this->cache);
        $this->groups  = new GroupResolver($store->groups(), $this->cache);
    }

    public function role(): string { return 'access_controller'; }
    public function store(): PdoPermissionStore { return $this->store; }
    public function graph(): RoleGraph { return $this->graph; }
    public function groups(): GroupResolver { return $this->groups; }

    /** @return array<string, mixed> 缓存命中 / 失效 / 后端统计，供守卫与接口对账 */
    public function cacheStats(): array { return $this->cache->stats(); }

    public function decide(?array $identity, string $action, array $resource = []): string
    {
        $base   = $this->baseDecision($identity, $action, $resource);
        // 策略钩子每次决策都必须执行，绝不缓存最终结果，否则请求级收紧策略会被绕过。
        $policy = (string) $this->hooks->apply('permission.decide', $base, $identity, $action, $resource);
        $final  = $this->tighten($base, $policy);
        $this->record($identity, $action, $resource, $final, $base, $policy);
        return $final;
    }

    public function allows(?array $identity, string $action, array $resource = []): bool
    {
        return $this->decide($identity, $action, $resource) === self::ALLOW;
    }

    public function catalog(): array { return $this->registry->all(); }

    public function grant(string $role, string $permission): bool
    {
        $changed = $this->store->grant($role, $permission);
        $this->cache->flush();
        return $changed;
    }

    public function revoke(string $role, string $permission): bool
    {
        $changed = $this->store->revoke($role, $permission);
        $this->cache->flush();
        return $changed;
    }

    /** @param array{type: string, id: string, subject: string, capability: string, allow: bool, field?: string} $entry */
    public function setAcl(array $entry): bool
    {
        $changed = $this->store->setAcl($entry);
        $this->cache->flush();
        return $changed;
    }

    /** @return array{ok: bool, reason: string, changed: bool} */
    public function setParent(string $role, string $parent, bool $remove = false): array
    {
        return $remove ? $this->graph->removeParent($role, $parent)
                       : $this->graph->setParent($role, $parent);
    }

    public function roleTree(): array { return $this->graph->tree(); }

    public function decisions(): array { return $this->recent; }

    private function baseDecision(?array $identity, string $action, array $resource): string
    {
        if (!$this->registry->has($action)) return self::ABSTAIN;

        $roles  = $this->groups->rolesFor($identity);
        $userId = (string) ($identity['user_id'] ?? '');
        $type   = (string) ($resource['type'] ?? '');
        $id     = (string) ($resource['id'] ?? '');
        $field  = (string) ($resource['field'] ?? '');
        $acl    = ($type !== '' && $id !== '')
            ? $this->cache->remember('acl:' . $type . ':' . $id, fn(): array => $this->store->aclFor($type, $id))
            : [];

        // 多角色匹配：任一角色命中即视为该条目对本主体生效。
        // 匿名主体角色集合为空，此时只可能命中 subject=all 的条目（显式 deny 对匿名同样有效）。
        $hits = function (array $entry) use ($roles, $userId, $action, $field): bool {
            if ($roles === []) {
                return $this->matcher->matches($entry, '', $userId, $action, $field);
            }
            foreach ($roles as $role) {
                if ($this->matcher->matches($entry, $role, $userId, $action, $field)) return true;
            }
            return false;
        };

        // 1) 显式 deny 对一切主体生效（含匿名），压过角色通配符、继承链与组成员关系。
        //    必须放在匿名判断之前：否则「禁止所有人访问某篇文章」对匿名失效。
        foreach ($acl as $entry) {
            if ((int) $entry['allow'] === 0 && $hits($entry)) return self::DENY;
        }
        // 匿名且无显式 deny → 不表态，由 allows() 按拒绝、由状态默认值决定是否公开可读
        if ($identity === null) return self::ABSTAIN;

        // 2) 资源级显式 allow
        foreach ($acl as $entry) {
            if ((int) $entry['allow'] === 1 && $hits($entry)) return self::ALLOW;
        }
        // 3) 角色许可：每个主体角色各自展开「自身 + 全部祖先」后取并集
        foreach ($roles as $role) {
            foreach ($this->graph->effectivePermissions($role) as $perm) {
                if ($this->matcher->permissionMatches($perm, $action)) return self::ALLOW;
            }
        }
        return self::ABSTAIN;
    }

    /** 策略只能往更严格方向移动，任何「提权」尝试都被忽略。 */
    private function tighten(string $base, string $policy): string
    {
        if ($base === self::ALLOW) {
            return $policy === self::ALLOW ? self::ALLOW : ($policy === self::DENY ? self::DENY : self::ABSTAIN);
        }
        if ($base === self::ABSTAIN) return $policy === self::DENY ? self::DENY : self::ABSTAIN;
        return self::DENY;
    }

    /** @param array<string, mixed> $resource */
    private function record(
        ?array $identity, string $action, array $resource,
        string $final, string $base, string $policy
    ): void {
        $entry = [
            'actor'    => (string) ($identity['username'] ?? 'anonymous'),
            'action'   => $action,
            'resource' => trim((string) ($resource['type'] ?? '') . ':' . (string) ($resource['id'] ?? ''), ':'),
            'field'    => (string) ($resource['field'] ?? ''),
            'decision' => $final,
            'reason'   => $base === $policy ? 'base' : 'policy_tightened',
            'at'       => date(DATE_ATOM),
        ];
        $this->recent[] = $entry;
        if (count($this->recent) > 100) array_shift($this->recent);
        try { $this->store->audit($entry); } catch (Throwable) { /* 留痕失败不阻断决策 */ }
        $this->hooks->emit('permission.decision', $entry);
    }
}
