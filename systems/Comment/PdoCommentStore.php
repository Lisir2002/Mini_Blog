<?php
declare(strict_types=1);
namespace Wx\Systems\Comment;

use Wx\Contracts\CommentStoreInterface;
use Wx\Contracts\DatabaseInterface;
use Wx\Systems\Data\Schema;

/** 评论系统的 MySQL / PDO 实现；与 MemoryCommentStore 实现同一契约，可整体替换。 */
final class PdoCommentStore implements CommentStoreInterface
{
    /** 写入白名单：列名绝不来自请求，避免把用户输入拼进 DDL / SQL 标识符位置。 */
    private const COLUMNS = [
        'post_slug', 'parent_id', 'root_id', 'path', 'depth',
        'author', 'author_id', 'body', 'status', 'created_at', 'ip_hash', 'deleted_at',
    ];

    public function __construct(private DatabaseInterface $db) {}

    public function ensureSchema(): void { Schema::apply($this->db, __DIR__ . '/migrations'); }

    public function add(array $comment): int
    {
        $data = [];
        foreach (self::COLUMNS as $column) {
            if (array_key_exists($column, $comment)) $data[$column] = $comment[$column];
        }
        $data['post_slug']  = (string) ($comment['post_slug'] ?? $comment['slug'] ?? '');
        $data['body']       = (string) ($comment['body'] ?? '');
        $data['status']     = (string) ($comment['status'] ?? CommentPolicy::PENDING);
        $data['created_at'] = (string) ($comment['created_at'] ?? date('Y-m-d H:i:s'));
        $columns = array_keys($data);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO ' . $this->table() . ' (' . implode(',', $columns) . ') VALUES (:'
            . implode(',:', $columns) . ')'
        );
        $stmt->execute($data);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function forPost(string $slug, int $limit = 0): array
    {
        $sql = 'SELECT id,' . implode(',', self::COLUMNS) . ' FROM ' . $this->table()
            . ' WHERE post_slug = :slug ORDER BY id ASC';
        // 上限走 SQL 而不是读回来再切：目的就是不让这几万行进 PHP 内存。
        if ($limit > 0) $sql .= ' LIMIT ' . $limit;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([':slug' => $slug]);
        return array_map($this->hydrate(...), $stmt->fetchAll());
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id,' . implode(',', self::COLUMNS) . ' FROM ' . $this->table() . ' WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function update(int $id, array $fields): bool
    {
        $set = [];
        $params = [':id' => $id];
        foreach (self::COLUMNS as $column) {
            if (!array_key_exists($column, $fields)) continue;
            $set[] = $column . ' = :' . $column;
            $params[':' . $column] = $fields[$column];
        }
        if ($set === []) return false;
        $stmt = $this->db->pdo()->prepare(
            'UPDATE ' . $this->table() . ' SET ' . implode(',', $set) . ' WHERE id = :id'
        );
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    public function countForPost(string $slug): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM ' . $this->table()
            . ' WHERE post_slug = :slug AND status = :status'
        );
        $stmt->execute([':slug' => $slug, ':status' => CommentPolicy::PUBLISHED]);
        return (int) $stmt->fetchColumn();
    }

    public function dropSchema(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS ' . $this->table());
    }

    /** 统一行形态：整数列转 int，并保留旧契约的 slug 别名，消费方无需区分底层。 */
    private function hydrate(array $row): array
    {
        foreach (['id', 'parent_id', 'root_id', 'depth', 'author_id'] as $column) {
            if (isset($row[$column])) $row[$column] = (int) $row[$column];
        }
        $row['slug'] = $row['post_slug'] ?? '';
        return $row;
    }

    private function table(): string { return $this->db->prefix() . 'comments'; }
}
