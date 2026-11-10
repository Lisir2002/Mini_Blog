<?php
declare(strict_types=1);
namespace Wx\Systems\Metric;

use Throwable;
use Wx\Contracts\DatabaseInterface;
use Wx\Systems\Data\Schema;

/**
 * MySQL 指标存储 —— 每小时、每指标、每维度一行，累加写入。
 *
 * 设计取舍：不存「每次请求一行」。那种表一天就能到千万行，查询要扫、清理要锁、
 * 备份要哭；而统计问题（多少、多快、错误率）只需要聚合值。代价是丢失单请求明细，
 * 这是刻意的：明细属于日志，统计属于指标，两者不混在同一张表。
 *
 * 写入以事务包裹：一次请求的 5~6 个采样点要么全部落库，要么全部不落，
 * 不会出现「请求记了、耗时没记」的半条数据。
 */
final class PdoMetricStore implements MetricStoreInterface
{
    public function __construct(private DatabaseInterface $db) {}

    public function ensureSchema(): void { Schema::apply($this->db, __DIR__ . '/migrations'); }

    public function write(array $points): void
    {
        if ($points === []) return;
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            $add = $pdo->prepare(
                'INSERT INTO ' . $this->table() . ' (bucket_at,name,dimension,kind,le,hits,total,updated_at)'
                . ' VALUES (:bucket_at,:name,:dimension,:kind,:le,:hits,:total,:updated_at)'
                . ' ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits),'
                . ' total = total + VALUES(total), updated_at = VALUES(updated_at)'
            );
            $set = $pdo->prepare(
                'INSERT INTO ' . $this->table() . ' (bucket_at,name,dimension,kind,le,hits,total,updated_at)'
                . ' VALUES (:bucket_at,:name,:dimension,:kind,:le,:hits,:total,:updated_at)'
                . ' ON DUPLICATE KEY UPDATE hits = VALUES(hits),'
                . ' total = VALUES(total), updated_at = VALUES(updated_at)'
            );
            $now = date('Y-m-d H:i:s');
            foreach ($points as $point) {
                $params = [
                    ':bucket_at' => $point['bucket_at'], ':name' => $point['name'],
                    ':dimension' => $point['dimension'], ':kind' => $point['kind'],
                    ':le' => $point['le'], ':hits' => $point['hits'],
                    ':total' => $point['total'], ':updated_at' => $now,
                ];
                ($point['kind'] === MetricCatalog::GAUGE ? $set : $add)->execute($params);
            }
            if ($own) $pdo->commit();
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function series(string $name, string $since, string $dimension = '', int $limit = 0): array
    {
        $sql = 'SELECT bucket_at,dimension,kind,le,hits,total FROM ' . $this->table()
            . ' WHERE name = :name AND bucket_at >= :since';
        $params = [':name' => $name, ':since' => $since];
        if ($dimension !== '') {
            $sql .= ' AND dimension = :dimension';
            $params[':dimension'] = $dimension;
        }
        $sql .= ' ORDER BY bucket_at ASC';
        if ($limit > 0) $sql .= ' LIMIT ' . $limit;
        return array_map(static fn(array $row): array => [
            'bucket_at' => (string) $row['bucket_at'],
            'dimension' => (string) $row['dimension'],
            'kind'      => (string) $row['kind'],
            'le'        => (string) $row['le'],
            'hits'      => (int) $row['hits'],
            'total'     => (float) $row['total'],
        ], $this->db->query($sql, $params));
    }

    public function summary(string $name, string $since): array
    {
        $rows = $this->db->query(
            'SELECT dimension,le,SUM(hits) AS hits,SUM(total) AS total FROM ' . $this->table()
            . ' WHERE name = :name AND bucket_at >= :since GROUP BY dimension,le',
            [':name' => $name, ':since' => $since]
        );
        return array_map(static fn(array $row): array => [
            'dimension' => (string) $row['dimension'],
            'le'        => (string) $row['le'],
            'hits'      => (int) $row['hits'],
            'total'     => (float) $row['total'],
        ], $rows);
    }

    public function prune(string $before): int
    {
        return $this->db->exec(
            'DELETE FROM ' . $this->table() . ' WHERE bucket_at < :before',
            [':before' => $before]
        );
    }

    public function durable(): bool { return true; }

    public function driver(): string { return 'mysql'; }

    private function table(): string { return $this->db->prefix() . 'metric_points'; }
}
