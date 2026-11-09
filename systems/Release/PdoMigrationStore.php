<?php
declare(strict_types=1);
namespace Wx\Systems\Release;

use Wx\Contracts\DatabaseInterface;

/** MySQL 迁移台账。表结构由 systems/Release/migrations/001_schema_migrations.sql 建。 */
final class PdoMigrationStore implements MigrationStoreInterface
{
    public function __construct(private DatabaseInterface $db) {}

    private function table(): string { return $this->db->prefix() . 'schema_migrations'; }

    public function all(): array
    {
        $rows = $this->db->query(
            'SELECT id,owner,checksum,batch,applied_at FROM ' . $this->table() . ' ORDER BY id'
        );
        return array_map(static fn(array $r): array => [
            'id' => (string) $r['id'], 'owner' => (string) $r['owner'],
            'checksum' => (string) $r['checksum'], 'batch' => (int) $r['batch'],
            'applied_at' => (string) $r['applied_at'],
        ], $rows);
    }

    public function find(string $id): ?array
    {
        foreach ($this->all() as $row) {
            if ($row['id'] === $id) return $row;
        }
        return null;
    }

    public function record(string $id, string $owner, string $checksum, int $batch): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO ' . $this->table() . ' (id,owner,checksum,batch,applied_at) VALUES (?,?,?,?,?)'
        );
        $stmt->execute([$id, $owner, $checksum, $batch, date('Y-m-d H:i:s')]);
    }

    public function forget(string $id): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM ' . $this->table() . ' WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function nextBatch(): int
    {
        $row = $this->db->query('SELECT MAX(batch) AS b FROM ' . $this->table());
        return (int) ($row[0]['b'] ?? 0) + 1;
    }
}
