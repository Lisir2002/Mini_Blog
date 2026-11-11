<?php
declare(strict_types=1);
namespace Wx\Pages\Stats;

/**
 * 看板区块构造 —— 把接口响应翻译成通用区块协议（links / table / chart 的行与点）。
 *
 * 与 StatsQuery 的分工：Query 负责「取哪几个接口、要多少条」，Blocks 负责
 * 「取回来的东西怎么摆成可读的行」。拆开是为了让每个文件都装得下职责预算，
 * 也让「窗口链接」「柱高比例」这类纯函数能被单独穷举。
 */
final class StatsBlocks
{
    /** 切换窗口的预设；当前窗口不在其中时由 windowLinks() 追加，绝不把用户悄悄拨回 24h。 */
    public const WINDOWS = [
        '24h' => '24 小时',
        '7d'  => '7 天',
        '30d' => '30 天',
    ];

    /** @return list<array{href: string, label: string, active: bool, note: string}> */
    public static function windowLinks(string $current): array
    {
        $items = [];
        foreach (self::WINDOWS as $value => $label) {
            $items[] = [
                'href'   => '/console/stats?window=' . rawurlencode($value),
                'label'  => $label, 'active' => $value === $current, 'note' => '',
            ];
        }
        if (!isset(self::WINDOWS[$current])) {
            $items[] = [
                'href'   => '/console/stats?window=' . rawurlencode($current),
                'label'  => $current, 'active' => true, 'note' => '非预设窗口',
            ];
        }
        return $items;
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
    public static function trafficRows(array $overview): array
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
    public static function opsRows(array $ops, int $limit): array
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
                'note' => '仅列前 ' . $limit . ' 项，明确声明未列全'];
        }
        return $rows;
    }

    /** @return list<array{k: string, v: string, note: string}> */
    public static function collectorRows(array $overview, array $catalog): array
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
