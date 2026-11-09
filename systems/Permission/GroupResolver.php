<?php
declare(strict_types=1);
namespace Wx\Systems\Permission;

/**
 * 主体分组解析器 —— 把「用户属于哪些组」翻译成「这个主体额外拥有哪些角色」。
 *
 * 组不是第四层权限，也不复制一套授权表：它只是一层**角色来源**。
 * 决策时主体角色集合 = 自身 role ∪ 所属组绑定的 role，
 * 之后完全走既有链路（角色继承展开 → 许可匹配 → 资源 ACL → 策略收紧）。
 * 因此显式 deny、fail-closed、不可提权这些语义一个字都不用改。
 *
 * 单一职权：只做组结构与成员关系；读写归 PdoPermissionStore，缓存归 PermissionCache。
 */
final class GroupResolver
{
    public const SUBJECT_TYPES = ['user'];

    /** @var list<string> 最近一次解析产生的告警（如未知主体类型） */
    private array $warnings = [];

    public function __construct(
        private PdoGroupStore $store,
        private PermissionCache $cache
    ) {}

    public function role(): string { return 'group_resolver'; }

    /** @return list<string> */
    public function warnings(): array { return $this->warnings; }

    /**
     * 主体角色集合：自身角色 + 所属组绑定的角色。
     * 匿名主体没有任何组来源，返回空集合（显式 deny 仍由 ACL 对 * 生效）。
     *
     * @param array<string, mixed>|null $identity
     * @return list<string>
     */
    public function rolesFor(?array $identity): array
    {
        if ($identity === null) return [];
        $roles = [];
        $primary = trim((string) ($identity['role'] ?? ''));
        if ($primary !== '') $roles[$primary] = true;

        $userId = (string) ($identity['user_id'] ?? '');
        if ($userId !== '' && $userId !== '0') {
            foreach ($this->groupRolesFor('user', $userId) as $role) {
                $role = trim((string) $role);
                if ($role !== '') $roles[$role] = true;
            }
        }
        $out = array_keys($roles);
        sort($out);
        return $out;
    }

    /** @return list<string> 某个主体通过组获得的全部角色（去重、排序） */
    public function groupRolesFor(string $subjectType, string $subjectId): array
    {
        if (!in_array($subjectType, self::SUBJECT_TYPES, true)) {
            $this->warnings[] = 'unknown_subject_type:' . $subjectType;
            return [];
        }
        if ($subjectId === '') return [];
        return $this->cache->remember(
            'group_roles:' . $subjectType . ':' . $subjectId,
            fn(): array => $this->store->groupRolesForSubject($subjectType, $subjectId)
        );
    }

    /** @return list<string> 某个主体所属的组 */
    public function groupsOf(string $subjectType, string $subjectId): array
    {
        if ($subjectId === '' || !in_array($subjectType, self::SUBJECT_TYPES, true)) return [];
        return $this->cache->remember(
            'groups_of:' . $subjectType . ':' . $subjectId,
            fn(): array => $this->store->groupsOfSubject($subjectType, $subjectId)
        );
    }

    /** 整张分组视图：每个组的绑定角色与成员，供接口与守卫对账。 */
    public function list(): array
    {
        $out = [];
        foreach ($this->store->groups() as $row) {
            $key = (string) $row['group_key'];
            $out[$key] = [
                'group'       => $key,
                'name'        => (string) $row['name'],
                'description' => (string) $row['description'],
                'color'       => (string) ($row['color'] ?? ''),
                'sort'        => (int) ($row['sort_order'] ?? 0),
                'roles'       => $this->store->groupRoles($key),
                'members'     => $this->store->groupSubjects($key),
            ];
        }
        return ['groups' => $out, 'total' => count($out), 'warnings' => $this->warnings];
    }

    /** 创建或更新组；key 由站长自定义（小写标识），name / 颜色 / 排序都只是展示属性。 */
    public function upsert(string $key, string $name, string $description, string $color = '', int $sort = 0): bool
    {
        $key = trim($key);
        if ($key === '' || !preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $key)) {
            return false;
        }
        $changed = $this->store->upsertGroup($key, $name, $description, $color, $sort);
        $this->cache->flush();
        return $changed;
    }

    /** 删除组：成员关系与角色绑定一并清理（外键由本层显式维护，不依赖数据库级联）。 */
    public function delete(string $key): bool
    {
        $changed = $this->store->deleteGroup(trim($key));
        $this->cache->flush();
        return $changed;
    }

    public function addRole(string $key, string $role): bool
    {
        $changed = $this->store->addGroupRole(trim($key), trim($role));
        $this->cache->flush();
        return $changed;
    }

    public function removeRole(string $key, string $role): bool
    {
        $changed = $this->store->removeGroupRole(trim($key), trim($role));
        $this->cache->flush();
        return $changed;
    }

    /** @param string $subject 'user:3' / '3'（省略类型时按 user 处理） */
    public function addMember(string $key, string $subject): bool
    {
        [$type, $id] = $this->parseSubject($subject);
        $changed = $this->store->addGroupSubject(trim($key), $type, $id);
        $this->cache->flush();
        return $changed;
    }

    public function removeMember(string $key, string $subject): bool
    {
        [$type, $id] = $this->parseSubject($subject);
        $changed = $this->store->removeGroupSubject(trim($key), $type, $id);
        $this->cache->flush();
        return $changed;
    }

    /** @return array{0: string, 1: string} */
    private function parseSubject(string $subject): array
    {
        $subject = trim($subject);
        if (str_contains($subject, ':')) {
            [$type, $id] = explode(':', $subject, 2);
            $type = in_array($type, self::SUBJECT_TYPES, true) ? $type : 'user';
            return [$type, trim($id)];
        }
        return ['user', $subject];
    }
}
