<?php
declare(strict_types=1);
namespace Wx\Systems\Permission;

/**
 * 主体分组的数据实现 —— 从 PdoPermissionStore 拆出的单一职责协作者。
 *
 * 拆分的理由不是「文件太长」而是「职权不同」：角色 / ACL / 审计属于授权数据，
 * 组与成员关系属于主体结构。两者共享同一个数据库连接，但没有任何一条 SQL 相互依赖。
 * 拆开后 PdoPermissionStore 回到预算内，GroupResolver 也只依赖组这一半。
 */
final class PdoGroupStore
{
    public function __construct(private \Wx\Contracts\DatabaseInterface $db) {}

    public function role(): string { return 'pdo_group_store'; }

    /** @return list<array<string, mixed>> */
    public function groups(): array
    {
        return $this->db->query(
            'SELECT group_key,name,description,color,sort_order FROM ' . $this->table('permission_groups')
            . ' ORDER BY sort_order ASC, group_key ASC'
        );
    }

    public function upsertGroup(string $key, string $name, string $description, string $color, int $sort): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO ' . $this->table('permission_groups')
            . ' (group_key,name,description,color,sort_order,created_at) VALUES (?,?,?,?,?,?)
               ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description),'
            . ' color = VALUES(color), sort_order = VALUES(sort_order)'
        );
        $stmt->execute([$key, $name, $description, $color, $sort, date('Y-m-d H:i:s')]);
        return $stmt->rowCount() > 0;
    }

    public function deleteGroup(string $key): bool
    {
        if ($key === '') return false;
        $pdo = $this->db->pdo();
        foreach (['permission_group_roles', 'permission_group_subjects'] as $name) {
            $stmt = $pdo->prepare('DELETE FROM ' . $this->table($name) . ' WHERE group_key = ?');
            $stmt->execute([$key]);
        }
        $stmt = $pdo->prepare('DELETE FROM ' . $this->table('permission_groups') . ' WHERE group_key = ?');
        $stmt->execute([$key]);
        return $stmt->rowCount() > 0;
    }

    /** @return list<string> */
    public function groupRoles(string $key): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT role FROM ' . $this->table('permission_group_roles') . ' WHERE group_key = ? ORDER BY role'
        );
        $stmt->execute([$key]);
        return array_map(static fn(array $r): string => (string) $r['role'], $stmt->fetchAll());
    }

    /** @return list<array{subject_type: string, subject_id: string}> */
    public function groupSubjects(string $key): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT subject_type,subject_id FROM ' . $this->table('permission_group_subjects')
            . ' WHERE group_key = ? ORDER BY subject_type, subject_id'
        );
        $stmt->execute([$key]);
        return array_map(static fn(array $r): array => [
            'subject_type' => (string) $r['subject_type'],
            'subject_id'   => (string) $r['subject_id'],
        ], $stmt->fetchAll());
    }

    /** @return list<string> 某主体通过组获得的全部角色（跨组去重） */
    public function groupRolesForSubject(string $subjectType, string $subjectId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT gr.role FROM ' . $this->table('permission_group_roles') . ' gr'
            . ' INNER JOIN ' . $this->table('permission_group_subjects') . ' gs'
            . '   ON gs.group_key = gr.group_key'
            . ' WHERE gs.subject_type = ? AND gs.subject_id = ? ORDER BY gr.role'
        );
        $stmt->execute([$subjectType, $subjectId]);
        return array_map(static fn(array $r): string => (string) $r['role'], $stmt->fetchAll());
    }

    /** @return list<string> 某主体所属的组 */
    public function groupsOfSubject(string $subjectType, string $subjectId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT group_key FROM ' . $this->table('permission_group_subjects')
            . ' WHERE subject_type = ? AND subject_id = ? ORDER BY group_key'
        );
        $stmt->execute([$subjectType, $subjectId]);
        return array_map(static fn(array $r): string => (string) $r['group_key'], $stmt->fetchAll());
    }

    public function addGroupRole(string $key, string $role): bool
    {
        if ($key === '' || $role === '') return false;
        $stmt = $this->db->pdo()->prepare(
            'INSERT IGNORE INTO ' . $this->table('permission_group_roles')
            . ' (group_key,role,created_at) VALUES (?,?,?)'
        );
        $stmt->execute([$key, $role, date('Y-m-d H:i:s')]);
        return $stmt->rowCount() > 0;
    }

    public function removeGroupRole(string $key, string $role): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM ' . $this->table('permission_group_roles') . ' WHERE group_key = ? AND role = ?'
        );
        $stmt->execute([$key, $role]);
        return $stmt->rowCount() > 0;
    }

    public function addGroupSubject(string $key, string $subjectType, string $subjectId): bool
    {
        if ($key === '' || $subjectId === '') return false;
        $stmt = $this->db->pdo()->prepare(
            'INSERT IGNORE INTO ' . $this->table('permission_group_subjects')
            . ' (group_key,subject_type,subject_id,created_at) VALUES (?,?,?,?)'
        );
        $stmt->execute([$key, $subjectType, $subjectId, date('Y-m-d H:i:s')]);
        return $stmt->rowCount() > 0;
    }

    public function removeGroupSubject(string $key, string $subjectType, string $subjectId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM ' . $this->table('permission_group_subjects')
            . ' WHERE group_key = ? AND subject_type = ? AND subject_id = ?'
        );
        $stmt->execute([$key, $subjectType, $subjectId]);
        return $stmt->rowCount() > 0;
    }

    private function table(string $name): string { return $this->db->prefix() . $name; }
}
