<?php
declare(strict_types=1);
namespace Wx\Systems\Release;

/** 内存迁移台账 —— 无数据库时的回退实现，语义与 PDO 版一致（批次、校验和、撤销）。 */
final class MemoryMigrationStore implements MigrationStoreInterface
{
    /** @var array<string, array{id: string, owner: string, checksum: string, batch: int, applied_at: string}> */
    private array $rows = [];

    public function all(): array
    {
        $rows = array_values($this->rows);
        usort($rows, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));
        return $rows;
    }

    public function find(string $id): ?array { return $this->rows[$id] ?? null; }

    public function record(string $id, string $owner, string $checksum, int $batch): void
    {
        $this->rows[$id] = [
            'id' => $id, 'owner' => $owner, 'checksum' => $checksum,
            'batch' => $batch, 'applied_at' => date('Y-m-d H:i:s'),
        ];
    }

    public function forget(string $id): void { unset($this->rows[$id]); }

    public function nextBatch(): int
    {
        $max = 0;
        foreach ($this->rows as $row) $max = max($max, $row['batch']);
        return $max + 1;
    }
}
