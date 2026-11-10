<?php
declare(strict_types=1);
namespace Wx\Systems\Metric;

/**
 * 指标采集器 —— 三类指标的唯一写入口。
 *
 *   counter   只增不减的累计量（请求数、错误数、事件数）
 *   gauge     某一时刻的存量（文章数、评论数、账号数）
 *   histogram 观测值落在哪个桶（耗时、单请求 SQL 条数）
 *
 * 两条硬规则：
 *   1. 名字必须在字典里，标签键必须在定义里 —— 防止拼写错误与基数爆炸；
 *   2. 写入失败绝不向业务抛异常 —— 统计是旁路，不能反过来把请求弄挂。
 *      失败只置 degraded 并在 error_log 留痕，由统计响应与 CLI 显式暴露。
 *
 * 采样先在内存合并（同一指标的同一维度一次请求只产生一行），
 * 由 kernel.shutdown 一次事务落库，避免每个埋点都开一次连接。
 */
final class MetricRecorder
{
    /** @var array<string, array<string, mixed>> */
    private array $buffer = [];
    private bool $degraded = false;
    private string $lastError = '';
    private int $rejected = 0;

    /**
     * @param array<string, array<string, mixed>> $catalog
     */
    public function __construct(
        private MetricStoreInterface $store,
        private array $catalog,
        private bool $enabled = true
    ) {}

    /** @param array<string, mixed> $labels */
    public function counter(string $name, array $labels = [], int $delta = 1): void
    {
        if ($delta <= 0) {
            if ($delta < 0) $this->drop('negative_delta');
            return;
        }
        $this->record($name, MetricCatalog::COUNTER, $labels, $delta, 0.0);
    }

    /** @param array<string, mixed> $labels */
    public function gauge(string $name, float $value, array $labels = []): void
    {
        if (!is_finite($value)) {
            $this->drop('invalid_value');
            return;
        }
        $this->record($name, MetricCatalog::GAUGE, $labels, 0, $value);
    }

    /** @param array<string, mixed> $labels */
    public function observe(string $name, float $value, array $labels = []): void
    {
        if (!is_finite($value) || $value < 0) {
            $this->drop('invalid_value');
            return;
        }
        $this->record($name, MetricCatalog::HISTOGRAM, $labels, 1, $value);
    }

    public function enabled(): bool { return $this->enabled; }

    /** @return array<string, mixed> 出现在统计响应的 collector 段 */
    public function status(): array
    {
        return [
            'durable'    => $this->store->durable(),
            'driver'     => $this->store->driver(),
            'degraded'   => $this->degraded,
            'last_error' => $this->lastError,
            'buffered'   => count($this->buffer),
            'rejected'   => $this->rejected,
            'bucket'     => 'hour',
        ];
    }

    /** 落库并清空缓冲。失败不回滚业务、不重试，只标记降级 —— 重试会把故障放大成雪崩。 */
    public function flush(): void
    {
        if ($this->buffer === []) return;
        $points = array_values($this->buffer);
        $this->buffer = [];
        if (!$this->enabled) return;
        try {
            $this->store->write($points);
        } catch (\Throwable $e) {
            $this->degraded = true;
            $this->lastError = $e->getMessage();
            error_log('wx: metric flush failed: ' . $e::class . ': ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $labels
     */
    private function record(string $name, string $kind, array $labels, int $hits, float $total): void
    {
        if (!$this->enabled) return;
        $def = $this->catalog[$name] ?? null;
        if ($def === null) {
            $this->drop('unknown_metric');
            return;
        }
        if (($def['kind'] ?? '') !== $kind) {
            $this->drop('kind_mismatch');
            return;
        }

        $allowed = (array) ($def['labels'] ?? []);
        $clean = [];
        foreach ($labels as $key => $value) {
            $label = MetricLabels::key((string) $key);
            if (!in_array($label, $allowed, true)) {
                // 未声明的标签不参与维度，但必须留下痕迹：否则「加了标签却没生效」永远查不出来。
                if ($name !== 'metric_dropped') $this->drop('label_ignored');
                continue;
            }
            $clean[$label] = $value;
        }

        $le = $kind === MetricCatalog::HISTOGRAM ? self::bucketOf($total) : '';
        $dimension = MetricLabels::format($clean);
        $key = $name . '|' . $dimension . '|' . $le;
        if (!isset($this->buffer[$key])) {
            $this->buffer[$key] = [
                'bucket_at' => self::bucketAt(), 'name' => $name, 'dimension' => $dimension,
                'kind' => $kind, 'le' => $le, 'hits' => 0, 'total' => 0.0,
            ];
        }
        if ($kind === MetricCatalog::GAUGE) {
            // 仪表是存量快照：同一小时重复采集以最后一次为准，累加会得出荒谬的「文章总数」。
            $this->buffer[$key]['hits']  = 0;
            $this->buffer[$key]['total'] = $total;
            return;
        }
        $this->buffer[$key]['hits']  += $hits;
        $this->buffer[$key]['total'] += $total;
    }

    private function drop(string $reason): void
    {
        $this->rejected++;
        if (!isset($this->catalog['metric_dropped'])) return;
        // 递归保护：丢弃计数器自己不再触发丢弃计数。
        $this->record('metric_dropped', MetricCatalog::COUNTER, ['reason' => $reason], 1, 0.0);
    }

    private static function bucketAt(): string
    {
        return date('Y-m-d H:00:00');
    }

    private static function bucketOf(float $value): string
    {
        foreach (MetricCatalog::BUCKETS as $bound) {
            if ($value <= $bound) return (string) $bound;
        }
        return '+Inf';
    }
}
