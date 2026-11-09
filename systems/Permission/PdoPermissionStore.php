<?php
declare(strict_types=1);
namespace Wx\Systems\Permission;

use Wx\Contracts\DatabaseInterface;
use Wx\Systems\Database\Schema;

/**
 * 权限数据的 MySQL 实现 —— 表与 SQL 全部归本系统层。
 * 角色许可（RBAC + 继承）、资源 ACL（含 field 维度）、决策留痕集中在这里，
 * 但只做读写，不做决策：展开归 RoleGraph，匹配归 AclMatcher，裁决归 AccessController。
 */
final class PdoPermissionStore
{
    private PdoGroupStore $groups;

    public function __construct(private DatabaseInterface $db)
    {
        $this->groups = new PdoGroupStore($db);
    }

    public function role(): string { return 'permission_store'; }

    /** 组与成员关系是另一类职权，拆在 PdoGroupStore；这里只提供入口，不转发方法。 */
    public function groups(): PdoGroupStore { return $this->groups; }

    public function ensureSchema(): void
    {
        Schema::apply($this->db, __DIR__ . '/migrations');
    }

    /** 幂等播种：只补默认值，绝不覆盖站长后续修改过的授权。 */
    public function seed(): void { (new PermissionSeeder($this->db))->run(); }

    /** @return array<string, array{role: string, description: string, permissions: list<string>}> */
    public function roles(): array
    {
        $rows = $this->db->query('SELECT role, description FROM ' . $this->table('permission_roles') . ' ORDER BY role');
        $out = [];
        foreach ($rows as $row) {
            $role = (string) $row['role'];
            $out[$role] = [
                'role' => $role, 'description' => (string) $row['description'],
                'permissions' => $this->grantsFor($role),
            ];
        }
        return $out;
    }

    /** @return list<string> */
    public function grantsFor(string $role): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT permission FROM ' . $this->table('permission_grants') . ' WHERE role = ? ORDER BY permission'
        );
        $stmt->execute([$role]);
        return array_map(static fn(array $r): string => (string) $r['permission'], $stmt->fetchAll());
    }

    /** @return list<string> 直接父角色（角色继承） */
    public function parentsFor(string $role): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT parent_role FROM ' . $this->table('permission_role_parents') . ' WHERE role = ? ORDER BY parent_role'
        );
        $stmt->execute([$role]);
        return array_map(static fn(array $r): string => (string) $r['parent_role'], $stmt->fetchAll());
    }

    public function setParent(string $role, string $parent): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT IGNORE INTO ' . $this->table('permission_role_parents')
            . ' (role,parent_role,created_at) VALUES (?,?,?)'
        );
        $stmt->execute([$role, $parent, date('Y-m-d H:i:s')]);
        return $stmt->rowCount() > 0;
    }

    public function removeParent(string $role, string $parent): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM ' . $this->table('permission_role_parents') . ' WHERE role = ? AND parent_role = ?'
        );
        $stmt->execute([$role, $parent]);
        return $stmt->rowCount() > 0;
    }

    /** @return list<array<string, mixed>> */
    public function aclFor(string $type, string $id): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT subject_type,subject_id,capability,allow,field FROM ' . $this->table('permission_acl')
            . ' WHERE resource_type = ? AND resource_id = ?'
        );
        $stmt->execute([$type, $id]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function aclList(): array
    {
        return $this->db->query(
            'SELECT resource_type,resource_id,subject_type,subject_id,capability,allow,field FROM '
            . $this->table('permission_acl') . ' ORDER BY id DESC LIMIT 100'
        );
    }

    public function grant(string $role, string $permission): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT IGNORE INTO ' . $this->table('permission_grants') . ' (role,permission,created_at) VALUES (?,?,?)'
        );
        $stmt->execute([$role, $permission, date('Y-m-d H:i:s')]);
        return $stmt->rowCount() > 0;
    }

    public function revoke(string $role, string $permission): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM ' . $this->table('permission_grants') . ' WHERE role = ? AND permission = ?'
        );
        $stmt->execute([$role, $permission]);
        return $stmt->rowCount() > 0;
    }

    /** @param array{type: string, id: string, subject: string, capability: string, allow: bool, field?: string} $e */
    public function setAcl(array $e): bool
    {
        [$subjectType, $subjectId] = $this->parseSubject((string) $e['subject']);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO ' . $this->table('permission_acl')
            . ' (resource_type,resource_id,subject_type,subject_id,capability,allow,field,created_at)'
            . ' VALUES (?,?,?,?,?,?,?,?)'
        );
        return $stmt->execute([
            (string) $e['type'], (string) $e['id'], $subjectType, $subjectId,
            (string) $e['capability'], $e['allow'] ? 1 : 0,
            (string) ($e['field'] ?? ''), date('Y-m-d H:i:s'),
        ]);
    }

    public function audit(array $entry): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO ' . $this->table('permission_audit')
            . ' (actor,action,resource,decision,reason,created_at) VALUES (?,?,?,?,?,?)'
        );
        $stmt->execute([
            (string) $entry['actor'], (string) $entry['action'], (string) $entry['resource'],
            (string) $entry['decision'], (string) $entry['reason'], date('Y-m-d H:i:s'),
        ]);
    }

    public function dropSchema(): void
    {
        foreach ([
            'permission_audit', 'permission_acl', 'permission_contract_overrides',
            'permission_grants', 'permission_group_roles', 'permission_group_subjects',
            'permission_groups', 'permission_role_parents', 'permission_roles',
        ] as $name) {
            $this->db->pdo()->exec('DROP TABLE IF EXISTS ' . $this->table($name));
        }
    }

    /** 'role:admin' / 'user:3' / '*' → [type, id] */
    private function parseSubject(string $subject): array
    {
        if ($subject === '*' || $subject === '') return ['all', ''];
        $parts = explode(':', $subject, 2);
        $type = in_array($parts[0], ['role', 'user'], true) ? $parts[0] : 'role';
        return [$type, $parts[1] ?? $parts[0]];
    }

    private function table(string $name): string { return $this->db->prefix() . $name; }
}
