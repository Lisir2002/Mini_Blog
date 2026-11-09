<?php
declare(strict_types=1);
namespace Wx\Systems\User;

use Wx\Contracts\DatabaseInterface;
use Wx\Contracts\UserProviderInterface;
use Wx\Systems\Database\Schema;

/**
 * 用户系统的 MySQL / PDO 实现。
 * 与 SessionUserProvider 实现同一契约：换实现只需改一行绑定，主题、插件、核心零改动。
 */
final class PdoUserProvider implements UserProviderInterface
{
    public function __construct(private DatabaseInterface $db) {}

    public function ensureSchema(): void { Schema::apply($this->db, __DIR__ . '/migrations'); }

    /** 演示账号仅用于本地原型；投产前必须删除或修改口令。 */
    public function seed(): void
    {
        $stmt = $this->db->pdo()->query('SELECT COUNT(*) FROM ' . $this->table());
        if ((int) $stmt->fetchColumn() > 0) return;
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO ' . $this->table() . ' (username,password_hash,display_name,role,created_at)
             VALUES (:username,:hash,:display,:role,:created_at)'
        );
        $insert->execute([
            ':username' => 'admin',
            ':hash' => password_hash('admin123', PASSWORD_DEFAULT),
            ':display' => '站点管理员',
            ':role' => 'admin',
            ':created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** API 场景下身份由令牌注入，会话上下文不再作为唯一来源。 */
    public function current(): ?array { return null; }

    public function find(int|string $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id,username,display_name,role,created_at FROM ' . $this->table() . ' WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => (int) $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** 按用户名查公开资料（user.lookup 钩子的实现细节；不返回口令摘要）。 */
    public function findByUsername(string $username): ?array
    {
        if ($username === '') return null;
        $stmt = $this->db->pdo()->prepare(
            'SELECT id,username,display_name,role,created_at FROM ' . $this->table() . ' WHERE username = :username LIMIT 1'
        );
        $stmt->execute([':username' => $username]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function verifyCredentials(string $username, string $password): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id,username,password_hash,display_name,role FROM ' . $this->table() . ' WHERE username = :username LIMIT 1');
        $stmt->execute([':username' => $username]);
        $row = $stmt->fetch();
        if ($row === false || !password_verify($password, (string) $row['password_hash'])) return null;
        unset($row['password_hash']);
        return $row;
    }

    public function dropSchema(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS ' . $this->table());
    }

    private function table(): string { return $this->db->prefix() . 'user_accounts'; }
}
