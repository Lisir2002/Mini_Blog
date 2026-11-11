<?php
declare(strict_types=1);
namespace Wx\Pages\Stats;

use InvalidArgumentException;
use Wx\Contracts\ApiRegistryInterface;

/**
 * 看板取数协作者 —— 复用既有 stats.* 接口，不新增接口、不认识 SQL、不自己算分位。
 *
 * 三条不变量：
 *  1. 采集器状态原样带出：durable / degraded / last_error 是页面的显式状态来源，
 *     绝不把「内存降级下的 0」当成健康数据。
 *  2. 截断必须声明：series 与 operations 各自的 truncated 都进 blocks，
 *     不假装「已经列全」。
 *  3. 任一接口失败都原样上抛，由页面转成显式不可用态（503），不吞成空数组。
 */
final class StatsQuery
{
    public const SERIES_NAME = 'http_requests';
    public const SERIES_LIMIT = 48;
    public const OPS_LIMIT = 12;

    public function __construct(private ApiRegistryInterface $registry) {}

    /** window 白名单校验放在页面层：非法值显式 422，不静默回落到默认窗口。 */
    public static function normalizeWindow(string $raw): string
    {
        $value = strtolower(trim($raw));
        if ($value === '') return '24h';
        // 窗口形如 24h / 7d；上限 720 小时（30 天），因此小时位允许三位数。
        if (preg_match('/^(\d{1,3})(h|d)$/', $value, $m) !== 1) {
            throw new InvalidArgumentException('window 只接受 1h~30d 形式，例如 24h / 7d');
        }
        $hours = (int) $m[1] * ($m[2] === 'd' ? 24 : 1);
        if ($hours < 1 || $hours > 720) {
            throw new InvalidArgumentException('window 超出允许范围（1 小时 ~ 30 天）');
        }
        return $value;
    }

    /** @return array<string, mixed> 概览、序列、排行、字典各取一次 */
    public function collect(string $window): array
    {
        $overview = (array) $this->registry->execute('stats.overview', ['window' => $window]);
        $series = (array) $this->registry->execute('stats.series', [
            'name' => self::SERIES_NAME, 'window' => $window, 'limit' => self::SERIES_LIMIT,
        ]);
        $operations = (array) $this->registry->execute('stats.operations', [
            'window' => $window, 'limit' => self::OPS_LIMIT,
        ]);
        $catalog = (array) $this->registry->execute('stats.catalog', []);
        return [
            'overview' => $overview, 'series' => $series,
            'operations' => $operations, 'catalog' => $catalog,
        ];
    }

    /** @return array{notice: array<string,mixed>, blocks: list<array<string,mixed>>, has_points: bool} */
    public function board(string $window, array $raw): array
    {
        $overview = (array) ($raw['overview'] ?? []);
        $series = (array) ($raw['series'] ?? []);
        $ops = (array) ($raw['operations'] ?? []);
        $catalog = (array) ($raw['catalog'] ?? []);

        $points = self::chartPoints((array) ($series['points'] ?? []));
        $hasPoints = $points !== [];
        $notice = StatsState::assess($overview, $hasPoints);

        $blocks = [];
        $blocks[] = [
            'type' => 'notice', 'id' => 'stats-notice',
            'tone' => $notice['tone'], 'state' => $notice['state'],
            'title' => $notice['title'], 'value' => $notice['message'],
        ];
        $blocks[] = ['type' => 'table', 'title' => '流量与耗时（窗口 ' . $window . '）',
            'rows' => self::trafficRows($overview)];
        $blocks[] = [
            'type' => 'chart', 'id' => 'stats-series',
            'title' => '按小时请求量 · ' . self::SERIES_NAME,
            'unit' => (string) ($series['unit'] ?? '次'),
            'points' => $points, 'empty' => !$hasPoints,
            'truncated' => !empty($series['truncated']),
        ];
        $blocks[] = ['type' => 'table', 'title' => '接口调用排行',
            'rows' => self::opsRows($ops)];
        $blocks[] = ['type' => 'table', 'title' => '采集器与字典',
            'rows' => self::collectorRows($overview, $catalog)];

        return ['notice' => $notice, 'blocks' => $blocks, 'has_points' => $hasPoints];
    }

    /**
     * 序列点 → 柱高比例。max=0 时全部 0，绝不除以零制造 Infinity / NAN；
     * 非零值给最小可见高度，避免「有数据却看不见」。
     * @return list<array{label: string, value: int, ratio: float}>
     */
    public static function chartPoints(array $points): array
    {
        $out = [];
        foreach ($points as $p) {
            if (!is_array($p)) continue;
            $value = (int) ($p['value'] ?? 0);
            $at = (string) ($p['at'] ?? '');
            $ts = strtotime($at);
            $out[] = ['label' => $ts === false ? $at : date('m-d H时', $ts), 'value' => $value, 'ratio' => 0.0];
        }
        $max = 0;
        foreach ($out as $p) $max = max($max, $p['value']);
        if ($max <= 0) return $out;
        foreach ($out as &$p) {
            $p['ratio'] = $p['value'] > 0
                ? round(max(0.03, $p['value'] / $max), 4) : 0.0;
        }
        unset($p);
        return $out;
    }

    /** @return list<array{k: string, v: string, note: string}> */
    private static function trafficRows(array $overview): array
    {
        $t = (array) ($overview['traffic'] ?? []);
        $a = (array) ($overview['api'] ?? []);
        $lat = (array) ($t['latency'] ?? []);
        return [
            ['k' => '请求数', 'v' => (string) (int) ($t['requests'] ?? 0), 'note' => 'counter 累加'],
            ['k' => '错误率', 'v' => number_format((float) ($t['error_rate'] ?? 0) * 100, 2) . '%', 'note' => '含 4xx/5xx'],
            ['k' => '客户端错误', 'v' => (string) (int) ($t['client_errors'] ?? 0), 'note' => '4xx'],
            ['k' => '平均速率', 'v' => (string) ($t['rps'] ?? 0) . ' req/s', 'note' => '按窗口秒数摊'],
            ['k' => 'p95 耗时', 'v' => (string) ($lat['p95'] ?? 0) . ' ms',
                'note' => !empty($lat['approx']) ? '固定桶近似（approx）' : '无样本'],
            ['k' => 'p99 耗时', 'v' => (string) ($lat['p99'] ?? 0) . ' ms',
                'note' => '样本 ' . (int) ($lat['samples'] ?? 0) . ' 次'],
            ['k' => '接口调用', 'v' => (string) (int) ($a['calls'] ?? 0), 'note' => 'api_calls'],
            ['k' => '接口失败率', 'v' => number_format((float) ($a['failure_rate'] ?? 0) * 100, 2) . '%', 'note' => 'code ≠ 0'],
        ];
    }

    /** @return list<array{k: string, v: string, note: string}> */
    private static function opsRows(array $ops): array
    {
        $rows = [];
        foreach ((array) ($ops['items'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $rows[] = [
                'k' => (string) ($item['operation'] ?? ''),
                'v' => (string) (int) ($item['calls'] ?? 0) . ' 次',
                'note' => '失败 ' . (int) ($item['failures'] ?? 0)
                    . ' · p95 ' . (string) ($item['p95'] ?? 0) . 'ms',
            ];
        }
        if ($rows === []) {
            $rows[] = ['k' => '窗口内没有接口调用记录', 'v' => '—', 'note' => '采集正常时属正常空态'];
        }
        if (!empty($ops['truncated'])) {
            $rows[] = ['k' => '共 ' . (int) ($ops['total'] ?? 0) . ' 项', 'v' => '已截断',
                'note' => '仅列前 ' . self::OPS_LIMIT . ' 项，明确声明未列全'];
        }
        return $rows;
    }

    /** @return list<array{k: string, v: string, note: string}> */
    private static function collectorRows(array $overview, array $catalog): array
    {
        $c = (array) ($overview['collector'] ?? []);
        $metrics = (array) ($catalog['metrics'] ?? []);
        return [
            ['k' => '驱动', 'v' => (string) ($c['driver'] ?? '(未知)'), 'note' => '采集器实现'],
            ['k' => '持久化', 'v' => !empty($c['durable']) ? '是' : '否',
                'note' => !empty($c['durable']) ? '聚合已落库' : '内存驱动：重启即丢'],
            ['k' => '降级', 'v' => !empty($c['degraded']) ? '是' : '否', 'note' => '写聚合失败过则为是'],
            ['k' => '最近错误', 'v' => (string) ($c['last_error'] ?? '') === '' ? '无' : (string) $c['last_error'], 'note' => '显式暴露，不隐藏'],
            ['k' => '丢弃采样', 'v' => (string) (int) ($c['rejected'] ?? 0), 'note' => '未知指标 / 未声明标签'],
            ['k' => '聚合桶', 'v' => (string) ($c['bucket'] ?? '(未知)'), 'note' => '小时聚合'],
            ['k' => '指标字典', 'v' => (string) count($metrics) . ' 项', 'note' => 'stats.catalog'],
        ];
    }
}
