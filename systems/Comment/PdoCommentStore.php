<?php
declare(strict_types=1);
namespace Wx\Systems\Comment;

use Wx\Contracts\CommentStoreInterface;
use Wx\Contracts\DatabaseInterface;
use Wx\Systems\Database\Schema;

/** 评论系统的 MySQL / PDO 实现；与 MemoryCommentStore 实现同一契约，可整体替换。 */
final class PdoCommentStore implements CommentStoreInterface
{
    public function __construct(private DatabaseInterface $db) {}

    public function ensureSchema(): void { Schema::apply($this->db, __DIR__ . '/migrations'); }

    public function add(array $comment): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO ' . $this->table() . ' (post_slug,author,body,status,created_at)
             VALUES (:post_slug,:author,:body,:status,:created_at)'
        );
        $stmt->execute([
            ':post_slug' => (string) ($comment['post_slug'] ?? $comment['slug'] ?? ''),
            ':author'    => (string) ($comment['author'] ?? ''),
            ':body'      => (string) ($comment['body'] ?? ''),
            ':status'    => (string) ($comment['status'] ?? 'pending'),
            ':created_at'=> (string) ($comment['created_at'] ?? date('Y-m-d H:i:s')),
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function forPost(string $slug): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id,post_slug,author,body,status,created_at FROM ' . $this->table()
            . ' WHERE post_slug = :slug AND status <> :spam ORDER BY id ASC'
        );
        $stmt->execute([':slug' => $slug, ':spam' => 'spam']);
        $rows = $stmt->fetchAll();
        // 兼容内存适配器的键名，契约消费方无需知道底层实现
        return array_map(static function (array $row): array {
            $row['id']  = (int) $row['id'];
            $row['slug'] = $row['post_slug'];
            return $row;
        }, $rows);
    }

    public function count(): int
    {
        return (int) $this->db->pdo()->query('SELECT COUNT(*) FROM ' . $this->table())->fetchColumn();
    }

    public function dropSchema(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS ' . $this->table());
    }

    private function table(): string { return $this->db->prefix() . 'comments'; }
}
