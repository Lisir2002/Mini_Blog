<?php
declare(strict_types=1);

/**
 * 统计系统运维 CLI —— 报告 / 裁剪 / 自检。
 *
 *   php tools/metrics.php report [24h]   文本概览：流量、错误率、分位耗时、操作排行、业务存量
 *   php tools/metrics.php prune [30]     删除早于 N 天的聚合点（默认取 config.metric.retention_days）
 *   php tools/metrics.php verify         自检：存储是否持久、字典是否完整、基数是否失控
 *
 * 边界：report / verify 只读；prune 是唯一会写库的命令，必须显式调用（建议配每日定时任务）。
 * 统计值按小时聚合，报告里的「今天」指最近 N 个小时，不是自然日。
 */

$root = require dirname(__DIR__) . '/core/bootstrap.php';

use Wx\Core\Kernel;
use Wx\Systems\Data\Db;
use Wx\Systems\Metric\MetricCatalog;
use Wx\Systems\Metric\MetricQuery;
use Wx\Systems\Metric\MetricStoreInterface;

$mode = (string) ($argv[1] ?? 'report');
if (!in_array($mode, ['report', 'prune', 'verify'], true)) {
    fwrite(STDERR, "用法：php tools/metrics.php [report [窗口] | prune [天数] | verify]\n");
    exit(2);
}

$kernel = new Kernel($root);
$kernel->boot();
/** @var MetricStoreInterface $store */
$store = $kernel->container()->resolve(MetricStoreInterface::class);
$catalog = MetricCatalog::sanitize((array) $kernel->hooks()->apply('metric.catalog', MetricCatalog::builtin(), $kernel));
$query = new MetricQuery($store, $catalog);

if ($mode === 'prune') {
    $days = (int) ($argv[2] ?? $kernel->config()->get('metric.retention_days', 30));
    if ($days < 1 || $days > 3650) {
        fwrite(STDERR, "保留天数必须在 1~3650 之间，收到：{$days}\n");
        exit(2);
    }
    $before = date('Y-m-d H:i:s', time() - $days * 86400);
    $removed = $store->prune($before);
    echo "裁剪完成：删除 {$removed} 行（bucket_at < {$before}，保留 {$days} 天）\n";
    if (!$store->durable()) {
        echo "注意：当前为内存存储（durable=false），重启即消失，没有历史可裁。\n";
    }
    exit(0);
}

if ($mode === 'verify') {
    $problems = [];
    if (!$store->durable()) {
        $problems[] = '存储不是持久实现（driver=' . $store->driver() . '），统计不能当历史账本';
    }
    $kinds = array_values(array_unique(array_column($catalog, 'kind')));
    sort($kinds);
    if ($kinds !== ['counter', 'gauge', 'histogram']) {
        $problems[] = '指标类型不完整：' . implode(',', $kinds);
    }
    $db = Db::optional($kernel);
    $rows = 0;
    if ($db !== null) {
        $table = $db->prefix() . 'metric_points';
        $rows = (int) ($db->query('SELECT COUNT(*) AS n FROM ' . $table)[0]['n'] ?? 0);
        // 基数告警：同一指标同一小时超过 1000 个维度，通常意味着把用户 ID / URL 放进了标签。
        $hot = $db->query(
            'SELECT name,bucket_at,COUNT(*) AS n FROM ' . $table
            . ' GROUP BY name,bucket_at HAVING n > 1000 ORDER BY n DESC LIMIT 5'
        );
        foreach ($hot as $row) {
            $problems[] = '基数失控：' . $row['name'] . ' @ ' . $row['bucket_at'] . ' 有 ' . $row['n'] . ' 个维度';
        }
    }
    echo '=== 统计系统自检 ===' . "\n";
    echo '  存储      ' . $store->driver() . '（durable=' . ($store->durable() ? 'true' : 'false') . '）' . "\n";
    echo '  指标数    ' . count($catalog) . "（counter / gauge / histogram）\n";
    echo '  聚合行数  ' . $rows . "\n";
    echo '  保留天数  ' . $kernel->config()->get('metric.retention_days', 30) . "（由 prune 执行）\n";
    if ($problems === []) {
        echo "  结果      通过\n";
        exit(0);
    }
    foreach ($problems as $problem) echo "  FAIL      {$problem}\n";
    echo "  结果      失败（" . count($problems) . " 项）\n";
    exit(1);
}

$window = $query->window((string) ($argv[2] ?? '24h'));
$gauges = (array) $kernel->hooks()->apply('metric.collect', [], $kernel);
$data = $query->overview($window, array_values(array_filter($gauges, static fn($g): bool => is_array($g))));
$ops = $query->operations($window, 10);
$traffic = $data['traffic'];

echo '=== 统计概览（' . $window['key'] . '，自 ' . $window['since'] . '） ===' . "\n";
printf(
    "  请求 %d · 5xx %d (%.2f%%) · 4xx %d · %.4f req/s\n",
    $traffic['requests'], $traffic['errors'], $traffic['error_rate'] * 100,
    $traffic['client_errors'], $traffic['rps']
);
$lat = $traffic['latency'];
if (($lat['samples'] ?? 0) > 0) {
    printf(
        "  耗时 p50 %s · p95 %s · p99 %s · 平均 %s ms（%d 样本，桶上界近似）\n",
        $lat['p50'], $lat['p95'], $lat['p99'], $lat['avg'], $lat['samples']
    );
} else {
    echo "  耗时 无样本（请求还没被统计到，或窗口内没有流量）\n";
}
$api = $data['api'];
printf("  接口 %d 次 · 失败 %d (%.2f%%)\n", $api['calls'], $api['failures'], $api['failure_rate'] * 100);
echo '  存储 ' . $store->driver() . '（durable=' . ($store->durable() ? 'true' : 'false') . '）· 保留 '
    . $kernel->config()->get('metric.retention_days', 30) . " 天\n";

if ($data['data'] !== []) {
    echo "  业务存量：\n";
    foreach ($data['data'] as $gauge) {
        $labels = $gauge['labels'] === [] ? '' : ' ' . json_encode($gauge['labels'], JSON_UNESCAPED_UNICODE);
        echo '    ' . $gauge['name'] . $labels . ' = ' . $gauge['value'] . "\n";
    }
}
if ($ops['items'] !== []) {
    echo "  操作 Top（按调用量）：\n";
    foreach ($ops['items'] as $item) {
        printf(
            "    %-28s %6d 次 · 失败 %d · p95 %s ms\n",
            $item['operation'], $item['calls'], $item['failures'], $item['p95']
        );
    }
}
