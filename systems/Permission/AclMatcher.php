<?php
declare(strict_types=1);
namespace Wx\Systems\Permission;

/**
 * ACL 条目匹配器 —— capability / subject / field 三个维度的纯判定。
 *
 * 不读写数据库、不认识请求对象，可脱离决策器独立验证（规矩 I 的协作者独立性）。
 * field 语义：
 *   - 条目 field 为空   = 整资源级，对任意请求（含不带 field 的请求）都生效；
 *   - 条目 field 非空   = 仅当请求 resource['field'] 精确相等时生效；
 *   - 请求不带 field    = 只有整资源级条目生效，字段级条目不参与本次判定。
 * 于是「禁止读某篇文章的 body」不会把整篇文章从列表里剔除。
 */
final class AclMatcher
{
    public function role(): string { return 'acl_matcher'; }

    /** @param array<string, mixed> $entry */
    public function matches(
        array $entry, string $role, string $userId, string $action, string $field
    ): bool {
        if (!$this->permissionMatches((string) ($entry['capability'] ?? ''), $action)) return false;
        if (!$this->fieldMatches((string) ($entry['field'] ?? ''), $field)) return false;
        return match ((string) ($entry['subject_type'] ?? 'all')) {
            'all'  => true,
            'role' => (string) ($entry['subject_id'] ?? '') === $role,
            'user' => (string) ($entry['subject_id'] ?? '') === $userId,
            default => false,
        };
    }

    /** 支持 * 与 content.* 前缀通配。 */
    public function permissionMatches(string $perm, string $action): bool
    {
        if ($perm === '*' || $perm === $action) return true;
        return str_ends_with($perm, '.*') && str_starts_with($action, substr($perm, 0, -1));
    }

    private function fieldMatches(string $entryField, string $requested): bool
    {
        return $entryField === '' || $entryField === $requested;
    }
}
