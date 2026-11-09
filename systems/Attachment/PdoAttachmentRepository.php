<?php
declare(strict_types=1);
namespace Wx\Systems\Attachment;

use Wx\Contracts\DatabaseInterface;
use Wx\Systems\Data\Schema;

/** 附件元数据的 MySQL / PDO 实现；与内存实现共享同一契约，可整体替换。 */
final class PdoAttachmentRepository implements AttachmentRepositoryInterface
{
    private const COLUMNS = [
        'post_slug', 'name', 'sha256', 'ext', 'mime', 'bytes',
        'visibility', 'uploader_id', 'created_at', 'deleted_at',
    ];

    public function __construct(private DatabaseInterface $db) {}

    public function ensureSchema(): void { Schema::apply($this->db, __DIR__ . '/migrations'); }

    public function add(array $file): int
    {
        $data = [];
        foreach (self::COLUMNS as $column) {
            if (array_key_exists($column, $file)) $data[$column] = $file[$column];
        }
        $data['name'] = (string) ($file['name'] ?? 'file');
        $data['sha256'] = (string) ($file['sha256'] ?? '');
        $data['created_at'] = (string) ($file['created_at'] ?? date('Y-m-d H:i:s'));
        $columns = array_keys($data);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO ' . $this->table() . ' (' . implode(',', $columns) . ') VALUES (:'
            . implode(',:', $columns) . ')'
        );
        $stmt->execute($data);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id,' . implode(',', self::COLUMNS) . ' FROM ' . $this->table() . ' WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? self::hydrate($row) : null;
    }

    public function findByHash(string $sha256): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id,' . implode(',', self::COLUMNS) . ' FROM ' . $this->table()
            . ' WHERE sha256 = :hash AND deleted_at IS NULL ORDER BY id ASC LIMIT 1'
        );
        $stmt->execute([':hash' => $sha256]);
        $row = $stmt->fetch();
        return is_array($row) ? self::hydrate($row) : null;
    }

    public function listForPost(string $slug): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id,' . implode(',', self::COLUMNS) . ' FROM ' . $this->table()
            . ' WHERE post_slug = :slug AND deleted_at IS NULL ORDER BY id DESC'
        );
        $stmt->execute([':slug' => $slug]);
        return array_map(self::hydrate(...), $stmt->fetchAll());
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

    public function softDelete(int $id): bool
    {
        return $this->update($id, ['deleted_at' => date('Y-m-d H:i:s')]);
    }

    public function countForPost(string $slug): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE post_slug = :slug AND deleted_at IS NULL'
        );
        $stmt->execute([':slug' => $slug]);
        return (int) $stmt->fetchColumn();
    }

    public function referenced(string $sha256): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE sha256 = :hash AND deleted_at IS NULL'
        );
        $stmt->execute([':hash' => $sha256]);
        return (int) $stmt->fetchColumn();
    }

    public function dropSchema(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS ' . $this->table());
    }

    private static function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['bytes'] = (int) $row['bytes'];
        $row['uploader_id'] = (int) $row['uploader_id'];
        return $row;
    }

    private function table(): string { return $this->db->prefix() . 'attachments'; }
}
