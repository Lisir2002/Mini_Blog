<?php
declare(strict_types=1);
namespace Wx\Systems\Metric;

use InvalidArgumentException;

/**
 * 指标读模型 —— 把「一行行聚合值」翻译成能回答问题的数字。
 *
 * 只做三件事：解析时间窗口、按维度聚合、把直方图换算成分位数。
 * 不读业务表、不依赖其它系统层：数据从存储来，业务存量由 metric.collect 提供。
 *
 * 分位数是桶内近似：桶上界即答案（5 / 10 / 25 ... / 10000 / +Inf）。
 * 这样做的好处是成本恒定、可解释；代价是精度受桶宽限制。响应里显式给出
 * `approx=true` 与桶上界，避免把 5ms 桶的答案说成「精确 3.7ms」。
 */
final class MetricQuery
{
    /** @param array<string, array<string, mixed>> $catalog */
    public function __construct(private MetricStoreInterface $store, private array $catalog) {}

    /** @return array{key: string, since: string, hours: int, seconds: int} */
    public function window(string $raw): array
    {
        $raw = strtolower(trim($raw));
        if ($raw === '') $raw = '24h';
        if (preg_match('/^(\d{1,2})(h|d)$/', $raw, $m) !== 1) {
            throw new InvalidArgumentException('window 只接受 1h~30d 形式，例如 1h / 24h / 7d');
        }
        $hours = (int) $m[1] * ($m[2] === 'd' ? 24 : 1);
        if ($hours < 1 || $hours > 720) {
            throw new InvalidArgumentException('window 超出允许范围（1 小时 ~ 30 天）');
        }
        $start = time() - ($hours - 1) * 3600;
        return [
            'key' => $raw, 'since' => date('Y-m-d H:00:00', $start),
            'hours' => $hours, 'seconds' => $hours * 3600,
        ];
    }

    /**
     * @param array{key: string, since: string, hours: int, seconds: int} $window
     * @param list<array{name: string, labels: array<string, mixed>, value: float}> $gauges
     * @return array<string, mixed>
     */
    public function overview(array $window, array $gauges): array
    {
        $requests     = $this->sum('http_requests', $window['since']);
        $errors       = $this->sum('http_errors', $window['since']);
        $clientErrors = $this->sum('http_client_errors', $window['since']);
        $latency      = $this->percentiles($this->summary('http_duration_ms', $window['since']));
        $api          = $this->apiTotals($window['since']);
        $rate         = $requests > 0 ? $errors / $requests : 0.0;

        return [
            'window' => $window,
            'traffic' => [
                'requests'      => $requests,
                'errors'        => $errors,
                'client_errors' => $clientErrors,
                'error_rate'    => round($rate, 4),
                'rps'           => round($requests / max(1, $window['seconds']), 4),
                'latency'       => $latency,
            ],
            'api' => $api,
            'data' => $gauges,
        ];
    }

    /**
     * @param array{key: string, since: string, hours: int, seconds: int} $window
     * @return array<string, mixed>
     */
    public function operations(array $window, int $limit = 50): array
    {
        $calls = [];
        foreach ($this->summary('api_calls', $window['since']) as $row) {
            $labels = MetricLabels::parse($row['dimension']);
            $op = (string) ($labels['operation'] ?? '');
            if ($op === '') continue;
            $calls[$op] ??= ['operation' => $op, 'calls' => 0, 'failures' => 0];
            $calls[$op]['calls'] += $row['hits'];
            if ((string) ($labels['code'] ?? '0') !== '0') $calls[$op]['failures'] += $row['hits'];
        }

        $hist = [];
        foreach ($this->summary('api_duration_ms', $window['since']) as $row) {
            $labels = MetricLabels::parse($row['dimension']);
            $op = (string) ($labels['operation'] ?? '');
            if ($op !== '') $hist[$op][] = $row;
        }

        foreach ($calls as $op => $entry) {
            $lat = $this->percentiles($hist[$op] ?? []);
            $calls[$op] += ['p50' => $lat['p50'], 'p95' => $lat['p95'], 'p99' => $lat['p99'], 'avg_ms' => $lat['avg']];
            $calls[$op]['failure_rate'] = $entry['calls'] > 0 ? round($entry['failures'] / $entry['calls'], 4) : 0.0;
        }

        $rows = array_values($calls);
        usort($rows, static fn(array $a, array $b): int => [$b['calls'], $a['operation']] <=> [$a['calls'], $b['operation']]);
        $total = count($rows);
        $limit = max(1, min(200, $limit));

        return [
            'window'    => $window,
            'total'     => $total,
            'truncated' => $total > $limit,
            'items'     => array_slice($rows, 0, $limit),
        ];
    }

    /**
     * @param array{key: string, since: string, hours: int, seconds: int} $window
     * @param array<string, mixed> $labels
     * @return array<string, mixed>
     */
    public function series(string $name, array $labels, array $window, int $limit = 500): array
    {
        $def = $this->catalog[$name] ?? null;
        if ($def === null) {
            throw new InvalidArgumentException('未知指标：' . $name . '（可用列表见 stats.catalog）');
        }
        $limit = max(1, min(2000, $limit));
        $dimension = $labels === [] ? '' : MetricLabels::format($labels);
        $rows = $this->store->series($name, $window['since'], $dimension, $limit + 1);
        $truncated = count($rows) > $limit;
        if ($truncated) $rows = array_slice($rows, 0, $limit);

        $points = [];
        foreach ($rows as $row) {
            $hits = (int) $row['hits'];
            $total = (float) $row['total'];
            $points[] = [
                'at'    => $row['bucket_at'],
                'hits'  => $hits,
                // 仪表把数值放在 total、hits 恒为 0：出站时统一叫 value，调用方不必记这个细节。
                'value' => $def['kind'] === MetricCatalog::GAUGE ? $total : $hits,
                'avg'   => $hits > 0 ? round($total / $hits, 3) : 0.0,
            ];
        }

        return [
            'window' => $window, 'name' => $name, 'kind' => $def['kind'], 'unit' => $def['unit'],
            'dimension' => $dimension, 'points' => $points, 'truncated' => $truncated,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function catalog(): array
    {
        $out = [];
        foreach ($this->catalog as $name => $def) {
            $out[] = [
                'name' => $name, 'kind' => $def['kind'], 'unit' => $def['unit'],
                'labels' => $def['labels'], 'source' => $def['source'], 'desc' => $def['desc'],
            ];
        }
        usort($out, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $out;
    }

    /** @return list<array{dimension: string, le: string, hits: int, total: float}> */
    private function summary(string $name, string $since): array { return $this->store->summary($name, $since); }

    private function sum(string $name, string $since): int
    {
        $total = 0;
        foreach ($this->summary($name, $since) as $row) $total += (int) $row['hits'];
        return $total;
    }

    /** @param list<array{dimension: string, le: string, hits: int, total: float}> $rows */
    private function percentiles(array $rows): array
    {
        $counts = [];
        $count = 0;
        $sum = 0.0;
        foreach ($rows as $row) {
            $le = (string) $row['le'];
            if ($le === '') continue;
            $counts[$le] = ($counts[$le] ?? 0) + (int) $row['hits'];
            $count += (int) $row['hits'];
            $sum += (float) $row['total'];
        }
        if ($count === 0) {
            return ['p50' => 0.0, 'p95' => 0.0, 'p99' => 0.0, 'avg' => 0.0, 'samples' => 0, 'approx' => false];
        }
        $keys = array_keys($counts);
        usort($keys, static fn(string $a, string $b): int => $a === '+Inf' ? 1 : ($b === '+Inf' ? -1 : (float) $a <=> (float) $b));
        $max = 0.0;
        foreach ($keys as $key) if ($key !== '+Inf') $max = max($max, (float) $key);

        $pick = static function (float $p) use ($counts, $keys, $count, $max): float {
            $target = $p * $count;
            $cum = 0;
            foreach ($keys as $key) {
                $cum += $counts[$key];
                if ($cum >= $target) return $key === '+Inf' ? $max : (float) $key;
            }
            return $max;
        };

        return [
            'p50' => $pick(0.50), 'p95' => $pick(0.95), 'p99' => $pick(0.99),
            'avg' => round($sum / $count, 2), 'samples' => $count, 'approx' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function apiTotals(string $since): array
    {
        $calls = 0;
        $failures = 0;
        $top = [];
        foreach ($this->summary('api_calls', $since) as $row) {
            $labels = MetricLabels::parse($row['dimension']);
            $op = (string) ($labels['operation'] ?? '');
            $calls += $row['hits'];
            $failed = (string) ($labels['code'] ?? '0') !== '0';
            if ($failed) $failures += $row['hits'];
            if ($op === '') continue;
            $top[$op] ??= ['operation' => $op, 'calls' => 0, 'failures' => 0];
            $top[$op]['calls'] += $row['hits'];
            if ($failed) $top[$op]['failures'] += $row['hits'];
        }
        $rows = array_values($top);
        usort($rows, static fn(array $a, array $b): int => $b['calls'] <=> $a['calls']);
        return [
            'calls' => $calls, 'failures' => $failures,
            'failure_rate' => $calls > 0 ? round($failures / $calls, 4) : 0.0,
            'top' => array_slice($rows, 0, 10),
        ];
    }
}
