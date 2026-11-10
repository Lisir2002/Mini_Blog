<?php
declare(strict_types=1);

use Wx\Systems\Metric\MemoryMetricStore;
use Wx\Systems\Metric\MetricCatalog;
use Wx\Systems\Metric\MetricQuery;
use Wx\Systems\Metric\MetricRecorder;

/**
 * 34 · 统计系统：三类指标、采集、存储、查询。
 *
 * 守卫重点不是「能算出一个数」，而是「算不出来时必须说出来」：
 * 未知指标被丢弃要计数、库不可用要标 durable=false、截断要标 truncated、
 * 写入失败不能反噬业务请求。
 */
echo "\n=== 34. 统计系统：三类指标 / 采集 / 存储 / 查询 ===\n";

/** @return array{0: MetricRecorder, 1: MemoryMetricStore, 2: MetricQuery} */
function metricProbe(bool $enabled = true): array
{
    $catalog = MetricCatalog::builtin();
    $store = new MemoryMetricStore();
    $recorder = new MetricRecorder($store, $catalog, $enabled);
    return [$recorder, $store, new MetricQuery($store, $catalog)];
}

check('字典自洽：三类指标齐备，名字 / 标签 / 描述合法，坏定义被拒绝', function (): void {
    $catalog = MetricCatalog::builtin();
    $kinds = array_values(array_unique(array_column($catalog, 'kind')));
    sort($kinds);
    truthy($kinds === ['counter', 'gauge', 'histogram'], '三类指标未齐备：' . implode(',', $kinds));
    truthy(count($catalog) >= 15, '指标过少，覆盖不足：' . count($catalog));
    foreach ($catalog as $name => $def) {
        truthy(preg_match('/^[a-z][a-z0-9_]{2,63}$/', $name) === 1, '非法指标名：' . $name);
        truthy($def['desc'] !== '' && $def['source'] !== '', '指标缺少描述或来源：' . $name);
        truthy(count($def['labels']) === count(array_unique($def['labels'])), '标签重复：' . $name);
    }
    $dirty = MetricCatalog::sanitize([
        'ok_metric' => ['kind' => 'counter', 'labels' => [], 'desc' => 'ok', 'source' => 'test'],
        'Bad-Name'  => ['kind' => 'counter', 'labels' => []],
        'bad_kind'  => ['kind' => 'timer', 'labels' => []],
        'not_array' => 'x',
    ]);
    truthy(array_keys($dirty) === ['ok_metric'], '非法定义未被拒绝：' . implode(',', array_keys($dirty)));
});

check('计数器累加、仪表覆盖、直方图分桶：三类语义各自正确', function (): void {
    [$rec, $store] = metricProbe();
    $rec->counter('http_requests', ['method' => 'GET', 'status' => '200'], 2);
    $rec->counter('http_requests', ['status' => '200', 'method' => 'get'], 3); // 标签顺序不同，必须合并
    $rec->gauge('content_posts', 7, ['status' => 'published']);
    $rec->gauge('content_posts', 9, ['status' => 'published']);               // 存量以最后一次为准
    $rec->observe('http_duration_ms', 3, ['method' => 'GET']);
    $rec->observe('http_duration_ms', 80, ['method' => 'GET']);
    $rec->flush();

    $rows = $store->summary('http_requests', '2000-01-01 00:00:00');
    truthy(count($rows) === 1 && $rows[0]['hits'] === 5, '计数未累加或标签未归一：' . json_encode($rows));
    $gauge = $store->summary('content_posts', '2000-01-01 00:00:00');
    truthy(count($gauge) === 1 && $gauge[0]['total'] === 9.0, '仪表未以最后一次为准');
    $hist = $store->summary('http_duration_ms', '2000-01-01 00:00:00');
    $bysLe = [];
    foreach ($hist as $row) $bysLe[$row['le']] = $row['hits'];
    truthy(($bysLe['5'] ?? 0) === 1 && ($bysLe['100'] ?? 0) === 1, '直方图分桶错误：' . json_encode($bysLe));
});

check('拒绝是显式的：未知指标 / 类型不符 / 非法标签都计入 metric_dropped', function (): void {
    [$rec, $store] = metricProbe();
    $rec->counter('definitely_not_a_metric');
    $rec->gauge('http_requests', 1);                        // 类型不符
    $rec->counter('http_requests', ['session' => 'abc']);   // 未声明标签
    $rec->counter('http_requests', ['method' => 'GET'], 0); // 零增量不写
    $rec->flush();

    $rows = $store->summary('metric_dropped', '2000-01-01 00:00:00');
    $reasons = [];
    foreach ($rows as $row) {
        preg_match('/reason=([a-z_]+)/', $row['dimension'], $m);
        $reasons[(string) ($m[1] ?? '')] = $row['hits'];
    }
    truthy(($reasons['unknown_metric'] ?? 0) === 1, '未知指标未被计数');
    truthy(($reasons['kind_mismatch'] ?? 0) === 1, '类型不符未被计数');
    truthy(($reasons['label_ignored'] ?? 0) === 1, '未声明标签未被计数');
    truthy(!isset($reasons['negative_delta']), '零增量不应算失败');
    $all = $store->summary('http_requests', '2000-01-01 00:00:00');
    truthy(count($all) === 1 && $all[0]['dimension'] === '', '未声明标签不应进入维度：' . json_encode($all));
});

check('分位数：1..100 的观测得到 p50=50 / p95=100 / p99=100，且标注近似', function (): void {
    [$rec, , $query] = metricProbe();
    for ($i = 1; $i <= 100; $i++) $rec->observe('http_duration_ms', (float) $i, ['method' => 'GET']);
    $rec->flush();
    $lat = $query->overview($query->window('1h'), [])['traffic']['latency'];
    truthy($lat['samples'] === 100, '样本数错误：' . $lat['samples']);
    truthy($lat['p50'] === 50.0 && $lat['p95'] === 100.0 && $lat['p99'] === 100.0, '分位数错误：' . json_encode($lat));
    truthy($lat['avg'] === 50.5, '均值错误：' . $lat['avg']);
    truthy($lat['approx'] === true, '分位数必须标注为桶内近似');
});

check('窗口解析：1h / 7d 合法，0h / 31d / 乱写一律拒绝而不是回退默认值', function (): void {
    [, , $query] = metricProbe();
    truthy($query->window('1h')['hours'] === 1, '1h 解析错误');
    truthy($query->window('7d')['hours'] === 168, '7d 解析错误');
    foreach (['0h', '31d', '24x', 'abc', '-1h'] as $bad) {
        $threw = false;
        try { $query->window($bad); } catch (InvalidArgumentException) { $threw = true; }
        truthy($threw, '非法窗口未被拒绝：' . $bad);
    }
});
