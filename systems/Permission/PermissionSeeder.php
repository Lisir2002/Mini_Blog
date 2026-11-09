<?php
declare(strict_types=1);
namespace Wx\Systems\Permission;

use Wx\Contracts\DatabaseInterface;

/**
 * 默认角色与许可的幂等播种 —— 从 PdoPermissionStore 拆出的单一职责协作者。
 *
 * 只补默认值，绝不覆盖站长修改过的授权：全部使用 INSERT IGNORE。
 * 播种属于「首次可用的基线数据」，与日常授权读写是两件事，故独立成类。
 */
final class PermissionSeeder
{
    /** @var array<string, string> 默认角色 */
    private const ROLES = [
        'admin'      => '站点管理员（显式授予通配符，可撤销）',
        'editor'     => '编辑：可管理全部文章与日志',
        'author'     => '作者：可写、可改自己的文章',
        'subscriber' => '订阅者：只读',
    ];

    /** @var array<string, list<string>> 默认角色许可 */
    private const GRANTS = [
        'admin'      => ['*'],
        'editor'     => ['content.*', 'logs.read', 'lifecycle.read', 'user.read', 'permission.read', 'page.console.read'],
        'author'     => ['content.read', 'content.create', 'content.edit'],
        'subscriber' => ['content.read'],
    ];

    public function __construct(private DatabaseInterface $db) {}

    public function role(): string { return 'permission_seeder'; }

    public function run(): void
    {
        $now = date('Y-m-d H:i:s');
        $pdo = $this->db->pdo();
        $roleStmt = $pdo->prepare(
            'INSERT IGNORE INTO ' . $this->table('permission_roles')
            . ' (role,description,created_at) VALUES (?,?,?)'
        );
        foreach (self::ROLES as $role => $desc) {
            $roleStmt->execute([$role, $desc, $now]);
        }
        $grantStmt = $pdo->prepare(
            'INSERT IGNORE INTO ' . $this->table('permission_grants')
            . ' (role,permission,created_at) VALUES (?,?,?)'
        );
        foreach (self::GRANTS as $role => $perms) {
            foreach ($perms as $perm) $grantStmt->execute([$role, $perm, $now]);
        }
    }

    private function table(string $name): string { return $this->db->prefix() . $name; }
}
