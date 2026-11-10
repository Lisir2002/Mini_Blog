<?php
declare(strict_types=1);
namespace Wx\Systems\Metric;

/**
 * 进程内指标存储 —— 库不可用时的降级实现。
 *
 * 它不假装自己是账本：durable() 恒为 false，统计响应会明确标注 `durable=false`。
 * 这样「统计为 0」与「库连不上导致统计为 0」不会长成同一张脸。
 */
final class MemoryMetricStore implements MetricStoreInterface
{
    /** @var array<string, array{bucket_at: string, name: string, dimension: string, kind: string, le: string, hits: int, total: float}> */
    private array $rows = [];

    public function write(array $points): void
    {
        foreach ($points as $point) {
            $key = implode('|', [
                $point['bucket_at'], $point['name'], $point['dimension'], $point['kind'], $point['le'],
            ]);
            if (!isset($this->rows[$key])) {
                $this->rows[$key] = $point;
                continue;
            }
            if ($point['kind'] === MetricCatalog::GAUGE) {
                // 仪表取最后一次观测：同一小时重复采集应覆盖，而不是累加。
                $this->rows[$key]['hits']  = $point['hits'];
                $this->rows[$key]['total'] = $point['total'];
                continue;
            }
            $this->rows[$key]['hits']  += $point['hits'];
            $this->rows[$key]['total'] += $point['total'];
        }
    }

    public function series(string $name, string $since, string $dimension = '', int $limit = 0): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            if ($row['name'] !== $name || $row['bucket_at'] < $since) continue;
            if ($dimension !== '' && $row['dimension'] !== $dimension) continue;
            $out[] = [
                'bucket_at' => $row['bucket_at'], 'dimension' => $row['dimension'],
                'kind' => $row['kind'], 'le' => $row['le'],
                'hits' => $row['hits'], 'total' => $row['total'],
            ];
        }
        usort($out, static fn(array $a, array $b): int => strcmp($a['bucket_at'], $b['bucket_at']));
        return $limit > 0 ? array_slice($out, 0, $limit) : $out;
    }

    public function summary(string $name, string $since): array
    {
        $groups = [];
        foreach ($this->rows as $row) {
            if ($row['name'] !== $name || $row['bucket_at'] < $since) continue;
            $key = $row['dimension'] . "\x1f" . $row['le'];
            $groups[$key] ??= ['dimension' => $row['dimension'], 'le' => $row['le'], 'hits' => 0, 'total' => 0.0];
            $groups[$key]['hits']  += $row['hits'];
            $groups[$key]['total'] += $row['total'];
        }
        return array_values($groups);
    }

    public function prune(string $before): int
    {
        $removed = 0;
        foreach ($this->rows as $key => $row) {
            if ($row['bucket_at'] >= $before) continue;
            unset($this->rows[$key]);
            $removed++;
        }
        return $removed;
    }

    public function durable(): bool { return false; }

    public function driver(): string { return 'memory'; }
}
