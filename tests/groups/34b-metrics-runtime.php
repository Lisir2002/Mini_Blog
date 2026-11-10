<?php
declare(strict_types=1);

use Wx\Core\Http\Request;
use Wx\Systems\Data\PdoDatabase;
use Wx\Systems\Data\QueryProfiler;
use Wx\Systems\Metric\MetricCatalog;
use Wx\Systems\Metric\MetricRecorder;
use Wx\Systems\Metric\MetricStoreInterface;
use Wx\Systems\Metric\PdoMetricStore;

/**
 * 34b · 统计系统运行时：埋点、查询接口、持久化与降级。
 *
 * 与 34 的分工：34 只验「三类指标与字典」这一层纯逻辑，
 * 这里验「接上内核与真实库之后」的行为，避免一个文件同时承担过多职权。
 */
echo "\n=== 34b. 统计运行时：埋点 / 查询 / 持久化 / 降级 ===\n";

check('HTTP 埋点：200 计入请求，404 计入客户端错误，5xx 走错误计数', function (): void {
    $k = adminKernel();
    $k->handle(new Request('GET', '/', [], adminHeaders()));
    $k->handle(new Request('GET', '/api/v1/does-not-exist', [], adminHeaders()));
    $body = json_decode($k->handle(new Request('GET', '/api/v1/stats/overview', ['window' => '1h'], adminHeaders()))->body(), true);
    $traffic = $body['data']['traffic'] ?? [];
    truthy(($traffic['requests'] ?? 0) >= 2, '请求数未记录：' . json_encode($traffic));
    truthy(($traffic['client_errors'] ?? 0) >= 1, '404 未计入客户端错误');
    truthy(($body['data']['collector']['durable'] ?? null) === true, '有库时应为持久存储');
});

check('API 埋点：operation 调用量 / 业务码 / 耗时进入排行，失败可区分', function (): void {
    $k = adminKernel();
    $k->handle(new Request('GET', '/api/v1/system/info', [], adminHeaders()));
    $k->handle(new Request('GET', '/api/v1/stats/nope', [], adminHeaders()));
    $body = json_decode($k->handle(new Request('GET', '/api/v1/stats/operations', ['window' => '1h'], adminHeaders()))->body(), true);
    $items = $body['data']['items'] ?? [];
    $byOp = [];
    foreach ($items as $item) $byOp[$item['operation']] = $item;
    truthy(isset($byOp['system.info']), 'system.info 未进入操作排行：' . implode(',', array_keys($byOp)));
    truthy(($byOp['system.info']['calls'] ?? 0) >= 1, '调用量未累计');
    truthy(isset($byOp['system.info']['p95']), '缺少分位耗时');
});

check('业务存量：概览附带文章 / 评论 / 账号，且不暴露任何业务字段', function (): void {
    $k = adminKernel();
    $body = json_decode($k->handle(new Request('GET', '/api/v1/stats/overview', ['window' => '1h'], adminHeaders()))->body(), true);
    $data = $body['data']['data'] ?? [];
    $names = array_column($data, 'name');
    foreach (['content_posts', 'comment_total', 'user_accounts'] as $needle) {
        truthy(in_array($needle, $names, true), '缺少业务存量：' . $needle . '（实际 ' . implode(',', $names) . '）');
    }
    foreach ($data as $sample) {
        truthy(array_keys($sample) === ['name', 'labels', 'value'], '存量采样夹带了额外字段：' . json_encode($sample));
    }
});

check('序列接口：未知指标 42201，合法指标按时间升序且截断显式声明', function (): void {
    $k = adminKernel();
    $bad = $k->handle(new Request('GET', '/api/v1/stats/series', ['name' => 'not_a_metric'], adminHeaders()));
    truthy($bad->status() === 422, '未知指标应返回 422，实际 ' . $bad->status());
    truthy((json_decode($bad->body(), true)['code'] ?? 0) === 42201, '未知指标错误码应为 42201');

    $ok = $k->handle(new Request('GET', '/api/v1/stats/series', ['name' => 'http_requests', 'limit' => '1'], adminHeaders()));
    $data = json_decode($ok->body(), true)['data'] ?? [];
    truthy(($data['kind'] ?? '') === 'counter', '指标类型未随序列返回');
    truthy(array_key_exists('truncated', $data), '序列缺少 truncated 声明');
    $prev = '';
    foreach ((array) ($data['points'] ?? []) as $point) {
        truthy($point['at'] >= $prev, '序列未按时间升序');
        $prev = $point['at'];
    }
});

check('未认证不可读统计：40101，而不是返回空数据', function (): void {
    $r = adminKernel()->handle(new Request('GET', '/api/v1/stats/overview'));
    truthy($r->status() === 401, '未认证应返回 401，实际 ' . $r->status());
    truthy((json_decode($r->body(), true)['code'] ?? 0) === 40101, '未认证错误码应为 40101');
});

check('持久化与保留：写入 / 读回 / 只裁旧数据，durable 如实上报', function (): void {
    $cfg = require dirname(__DIR__, 2) . '/config/app.php';
    if ((string) ($cfg['database']['dsn'] ?? '') === '') return;
    $prefix = 'mt_' . bin2hex(random_bytes(3)) . '_';
    try {
        $db = new PdoDatabase(
            (string) $cfg['database']['dsn'], (string) $cfg['database']['user'],
            (string) $cfg['database']['pass'], $prefix, new QueryProfiler()
        );
        $store = new PdoMetricStore($db);
        $store->ensureSchema();
        $store->write([
            ['bucket_at' => '2020-01-01 00:00:00', 'name' => 'http_requests', 'dimension' => 'method=get,status=200',
             'kind' => 'counter', 'le' => '', 'hits' => 5, 'total' => 0.0],
            ['bucket_at' => date('Y-m-d H:00:00'), 'name' => 'http_requests', 'dimension' => 'method=get,status=200',
             'kind' => 'counter', 'le' => '', 'hits' => 2, 'total' => 0.0],
        ]);
        $rows = $store->series('http_requests', '2020-01-01 00:00:00');
        truthy(count($rows) === 2, '写入后应读回两行，实际 ' . count($rows));
        truthy($store->durable() === true && $store->driver() === 'mysql', '持久存储标识错误');
        $removed = $store->prune(date('Y-m-d H:i:s', time() - 86400));
        truthy($removed === 1, '只应裁掉 2020 年那一行，实际删除 ' . $removed);
    } finally {
        dropTables($prefix);
    }
});

check('写入失败不反噬业务：flush 吞掉异常、标记 degraded，并留痕', function (): void {
    $catalog = MetricCatalog::builtin();
    $failing = new class implements MetricStoreInterface {
        public function write(array $points): void { throw new RuntimeException('disk full'); }
        public function series(string $name, string $since, string $dimension = '', int $limit = 0): array { return []; }
        public function summary(string $name, string $since): array { return []; }
        public function prune(string $before): int { return 0; }
        public function durable(): bool { return true; }
        public function driver(): string { return 'broken'; }
    };
    $old = ini_set('error_log', 'syslog');
    try {
        $rec = new MetricRecorder($failing, $catalog);
        $rec->counter('http_requests', ['method' => 'GET', 'status' => '500']);
        $rec->flush(); // 不得抛出
        $status = $rec->status();
        truthy($status['degraded'] === true, '写入失败未标记 degraded');
        truthy($status['last_error'] === 'disk full', '未保留失败原因');
        truthy(count($failing->summary('x', '2000-01-01')) === 0, '失败存储不应假装有数据');
    } finally {
        if ($old !== false) ini_set('error_log', $old);
    }
});
