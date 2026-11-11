<?php
declare(strict_types=1);

use Wx\Contracts\ApiRegistryInterface;
use Wx\Pages\Stats\StatsBlocks;
use Wx\Pages\Stats\StatsQuery;
use Wx\Pages\Stats\StatsState;

/**
 * 40 · 统计看板：纯逻辑与状态判定。
 *
 * 这一组守的是「不能让 0 冒充健康」：采集器内存降级、写聚合失败、窗口内无采样、
 * 接口执行失败，四种情况必须能被分别断言。若都渲染成一排 0，运维会把
 * 「统计已经瞎了」读成「站点没流量」。
 */
echo "\n=== 40. 统计看板：窗口 / 图表 / 四态判定 ===\n";

check('窗口白名单：合法值归一，非法值显式拒绝而不是静默回落默认', function (): void {
    truthy(StatsQuery::normalizeWindow('') === '24h', '缺省窗口应为 24h');
    truthy(StatsQuery::normalizeWindow('7d') === '7d', '7d 应原样通过');
    truthy(StatsQuery::normalizeWindow('24H') === '24h', '大小写应归一');
    truthy(StatsQuery::normalizeWindow('720h') === '720h', '上界 720h 应通过');

    $bad = ['99x', '0h', '31d', 'abc', '1.5h', '-2h', '25'];
    foreach ($bad as $v) {
        $thrown = false;
        try {
            StatsQuery::normalizeWindow($v);
        } catch (InvalidArgumentException) {
            $thrown = true;
        }
        truthy($thrown, '非法窗口「' . $v . '」被接受了');
    }
});

check('柱状图比例：max=0 不制造 NaN，非零值有最小可见高度', function (): void {
    $zero = StatsBlocks::chartPoints([
        ['at' => '2026-11-11 10:00:00', 'value' => 0],
        ['at' => '2026-11-11 11:00:00', 'value' => 0],
    ]);
    truthy(count($zero) === 2, '零点被丢弃了');
    foreach ($zero as $p) {
        truthy($p['ratio'] === 0.0, '全零时比例必须为 0，不能是 NaN / Infinity');
    }

    $mixed = StatsBlocks::chartPoints([
        ['at' => '2026-11-11 10:00:00', 'value' => 100],
        ['at' => '2026-11-11 11:00:00', 'value' => 1],
        ['at' => 'bad-date', 'value' => 0],
    ]);
    truthy($mixed[0]['ratio'] === 1.0, '最大值应为满格');
    truthy($mixed[1]['ratio'] >= 0.03, '极小值也要有可见高度，否则「有数据却看不见」');
    truthy($mixed[2]['label'] === 'bad-date', '脏时间不能伪装成今天');

    truthy(StatsBlocks::chartPoints([['at' => 'x', 'value' => -5]])[0]['value'] === -5,
        '负值不该被悄悄抹平（由上游保证非负，页面不隐藏异常）');
});

check('四态判定：未持久化 / 降级 / 空窗口 / 正常，互不冒充', function (): void {
    $ok = StatsState::assess([
        'collector' => ['durable' => true, 'degraded' => false, 'last_error' => ''],
        'traffic' => ['requests' => 12],
    ], true);
    truthy($ok['state'] === StatsState::OK && $ok['tone'] === 'ok', '健康数据被误判');

    $memory = StatsState::assess([
        'collector' => ['durable' => false, 'degraded' => false, 'last_error' => ''],
        'traffic' => ['requests' => 0],
    ], false);
    truthy($memory['state'] === StatsState::DEGRADED, 'durable=false 必须判为降级');
    truthy(str_contains($memory['message'], '不能当作全站真实流量'), '降级文案没有说清「数字不可信」');

    $failed = StatsState::assess([
        'collector' => ['durable' => true, 'degraded' => true, 'last_error' => 'disk full'],
        'traffic' => ['requests' => 3],
    ], true);
    truthy($failed['state'] === StatsState::DEGRADED, '写失败过的采集器必须判为降级');
    truthy(str_contains($failed['message'], 'disk full'), '最近错误没有暴露给运维');

    $empty = StatsState::assess([
        'collector' => ['durable' => true, 'degraded' => false, 'last_error' => ''],
        'traffic' => ['requests' => 0],
    ], false);
    truthy($empty['state'] === StatsState::EMPTY, '窗口内无采样应判为空态');
    truthy(str_contains($empty['message'], '不是故障'), '空态文案没有说明「这不是故障」');

    // 有请求但没画点：不该说成「没有采样」。
    $noPoints = StatsState::assess([
        'collector' => ['durable' => true, 'degraded' => false, 'last_error' => ''],
        'traffic' => ['requests' => 50],
    ], false);
    truthy($noPoints['state'] === StatsState::OK, '有请求却无序列点时不该判成空窗口');
});

check('不可用态：接口失败 503、窗口非法 422，且都不渲染一排假 0', function (): void {
    $fail = StatsState::unavailable('24h', new RuntimeException('/secret/path 爆了'));
    truthy($fail['status'] === 503, '接口失败应 503，实际 ' . $fail['status']);
    truthy($fail['payload']['stats_state'] === StatsState::UNAVAILABLE, '不可用态标记缺失');
    truthy(!str_contains((string) $fail['payload']['state']['message'], '/secret/path'),
        '内部异常详情泄露到读者文案');
    truthy($fail['payload']['blocks'] === [], '不可用态不该带出一排列全 0 的区块');

    $bad = StatsState::unavailable('99x', new InvalidArgumentException('window 只接受 1h~30d'), true);
    truthy($bad['status'] === 422, '窗口非法应 422，实际 ' . $bad['status']);
    truthy(($bad['payload']['state']['phase'] ?? '') === 'failed', '422 缺显式失败相位');
    truthy(($bad['payload']['state']['actions'] ?? []) !== [], '失败态没有给可恢复出口');
});

check('看板组装：截断显式、采集器字段齐全、空窗口不编造排行', function (): void {
    $fake = new class implements ApiRegistryInterface {
        public function endpoints(): array { return []; }
        public function find(string $id): ?array { return null; }
        public function match(string $method, string $path): ?array { return null; }
        public function registerOperation(string $operation, callable $handler): void {}
        public function execute(string $operation, array $input): mixed
        {
            return match ($operation) {
                'stats.overview' => [
                    'traffic' => ['requests' => 5, 'error_rate' => 0.2, 'client_errors' => 1,
                        'rps' => 0.1, 'latency' => ['p95' => 9, 'p99' => 12, 'samples' => 5, 'approx' => true]],
                    'api' => ['calls' => 3, 'failure_rate' => 0.0],
                    'collector' => ['driver' => 'pdo', 'durable' => true, 'degraded' => false,
                        'last_error' => '', 'rejected' => 0, 'bucket' => '1h'],
                ],
                'stats.series' => ['unit' => '次', 'truncated' => true,
                    'points' => [['at' => '2026-11-11 10:00:00', 'value' => 5]]],
                'stats.operations' => ['total' => 99, 'truncated' => true, 'items' => [
                    ['operation' => 'content.post', 'calls' => 3, 'failures' => 0, 'p95' => 9],
                ]],
                'stats.catalog' => ['metrics' => [['name' => 'http_requests']]],
                default => [],
            };
        }
    };

    $q = new StatsQuery($fake);
    $raw = $q->collect('24h');
    $board = $q->board('24h', $raw);

    truthy($board['has_points'] === true, '有点时 has_points 应为真');
    $types = array_map(static fn(array $b): string => (string) ($b['type'] ?? ''), $board['blocks']);
    foreach (['notice', 'table', 'chart'] as $need) {
        truthy(in_array($need, $types, true), '看板缺少区块类型：' . $need);
    }

    $chart = [];
    foreach ($board['blocks'] as $b) {
        if (($b['type'] ?? '') === 'chart') $chart = $b;
    }
    truthy(($chart['truncated'] ?? null) === true, '序列被截断却没有声明');

    $opsRows = [];
    $collectorRows = [];
    foreach ($board['blocks'] as $b) {
        if (($b['type'] ?? '') !== 'table') continue;
        if (($b['title'] ?? '') === '接口调用排行') $opsRows = (array) ($b['rows'] ?? []);
        if (($b['title'] ?? '') === '采集器与字典') $collectorRows = (array) ($b['rows'] ?? []);
    }
    truthy(str_contains(json_encode($opsRows, JSON_UNESCAPED_UNICODE), '已截断'), '排行截断没有在表格里声明');
    truthy(str_contains(json_encode($opsRows, JSON_UNESCAPED_UNICODE), '99'), '排行没有说出总数');

    $flat = json_encode($collectorRows, JSON_UNESCAPED_UNICODE);
    foreach (['驱动', '持久化', '降级', '最近错误', '丢弃采样', '指标字典'] as $k) {
        truthy(str_contains($flat, $k), '采集器区块缺字段：' . $k);
    }
});

check('看板清单：/console/stats、admin 布局、metric.read 权限，且页面不产 HTML', function () use ($root): void {
    $meta = json_decode((string) file_get_contents($root . '/pages/Stats/page.json'), true);
    truthy(is_array($meta), 'page.json 不可解析');
    truthy(($meta['routes'] ?? []) === ['/console/stats'], '路由声明不符');
    truthy(($meta['layout'] ?? '') === 'admin', '看板应复用 admin 布局，而不是新造槽位');
    truthy(($meta['access']['auth'] ?? '') === 'admin', '看板不该对匿名开放');
    truthy(($meta['access']['permission'] ?? '') === 'metric.read', '看板缺少统计读权限声明');

    foreach (glob($root . '/pages/Stats/*.php') ?: [] as $f) {
        $src = (string) file_get_contents($f);
        foreach (['<div', 'class=', '<style', '<script', '<html'] as $needle) {
            truthy(!str_contains($src, $needle), basename($f) . ' 里出现呈现痕迹：' . $needle);
        }
        truthy(!str_contains($src, 'SELECT ') && !str_contains($src, 'INSERT '),
            basename($f) . ' 里出现 SQL，页面不该认识数据库');
    }
});

check('区块协议：两款主题都支持 notice 与 chart，且追加到共享设计系统', function () use ($root): void {
    $front = (string) file_get_contents($root . '/themes/GlassFront/templates/partials/blocks.php');
    $back  = (string) file_get_contents($root . '/themes/Glass/templates/partials/blocks.php');
    truthy($front === $back, '两款 blocks.php 不是逐字同源');

    foreach (['notice', 'chart', 'data-chart-points', 'data-stats-state'] as $needle) {
        truthy(str_contains($front, $needle), '区块协议缺少：' . $needle);
    }

    $cssA = (string) file_get_contents($root . '/themes/GlassFront/assets/glass.css');
    $cssB = (string) file_get_contents($root . '/themes/Glass/assets/glass.css');
    truthy($cssA === $cssB, '两款 glass.css 不是逐字同源');
    foreach (['.glass-chart', '.glass-notice'] as $needle) {
        truthy(str_contains($cssA, $needle), '共享设计系统缺少样式：' . $needle);
    }
});

check('窗口切换是链接组：GET 可分享，active 唯一，非预设窗口不被悄悄拨回 24h', function (): void {
    $std = StatsBlocks::windowLinks('7d');
    truthy(count($std) === 3, '预设窗口应有 3 项，实际 ' . count($std));
    $active = array_values(array_filter($std, static fn(array $i): bool => $i['active']));
    truthy(count($active) === 1 && $active[0]['label'] === '7 天', 'active 不唯一或指错窗口');
    foreach ($std as $item) {
        truthy(str_starts_with($item['href'], '/console/stats?window='), '链接没有指向看板本身');
        truthy(!str_contains($item['href'], ' '), '链接含空格，浏览器会截断');
    }

    $custom = StatsBlocks::windowLinks('12h');
    truthy(count($custom) === 4, '非预设窗口应追加一项，实际 ' . count($custom));
    $last = $custom[3];
    truthy($last['active'] && $last['label'] === '12h', '非预设窗口没有以当前值出现');
    truthy(str_contains($last['note'], '非预设'), '追加项没有说明它是非预设窗口');
});
