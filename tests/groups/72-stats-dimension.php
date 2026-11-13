<?php
declare(strict_types=1);

use Wx\Contracts\ApiRegistryInterface;
use Wx\Pages\Stats\StatsDimension;
use Wx\Pages\Stats\StatsQuery;

/**
 * 72 · 维度下钻：精确匹配口径与完整维度串（纯函数 / 静态核对）
 *
 * stats.series 的 dimension 是等值比较，少一个标签就永远查不到。契约旧示例
 * operation=content.posts 命中不了真实 api_calls（采集侧是 operation+code 双标签）。
 * 这一组锁住：页面必须把「带齐标签」当硬约束，从排行反推的链接必须是
 * 完整可命中的串，字典没声明时绝不编造入口。运行时闭环见 72b。
 */
echo "\n=== 72. 维度下钻：精确匹配 / 完整维度串 / 不编造入口 ===\n";

$catalog = ['metrics' => [
    ['name' => 'http_requests', 'labels' => ['method', 'status']],
    ['name' => 'api_calls', 'labels' => ['operation', 'code']],
    ['name' => 'db_queries', 'labels' => []],
]];
$ops = ['items' => [
    ['operation' => 'content.posts', 'calls' => 12],
    ['operation' => 'users.me', 'calls' => 5],
]];

$norm = static fn(string $raw, string $metric = 'api_calls'): array
    => StatsDimension::normalize($raw, $catalog, $metric);
/** 断言必须被拒，并把原因带回来给断言核对。 */
$reject = static function (callable $fn): string {
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }
    throw new RuntimeException('本该显式拒绝却放行了');
};

check('空维度表示全量；非空串按标签名字母序规范化成可比较的串', function () use ($norm): void {
    $all = $norm('');
    truthy($all['dimension'] === '' && $all['labels'] === [], '空维度不该被改写成别的含义');
    truthy($all['required'] === ['operation', 'code'], '字典声明的标签没带出来');

    $a = $norm('operation=content.posts,code=0');
    truthy($a['dimension'] === 'code=0,operation=content.posts', '双标签没有按字母序重排');
    truthy($a['labels'] === ['code' => '0', 'operation' => 'content.posts'], '标签解析结果不对');

    $b = StatsDimension::normalize('status=200,method=GET',
        ['metrics' => [['name' => 'http_requests', 'labels' => ['method', 'status']]]], 'http_requests');
    truthy($b['dimension'] === 'method=get,status=200', '值没有与采集侧同形地小写化');
});

check('精确匹配：缺标签 / 非法键 / 脏值 / 重复键 / 过长一律显式拒绝',
    function () use ($norm, $reject): void {
        truthy(str_contains($reject(fn() => $norm('operation=content.posts')), '必须带齐'),
            '缺 code 标签必须说清「必须带齐全部标签」');
        truthy(str_contains($reject(fn() => $norm('foo=bar,code=0,operation=x')), '不在'),
            '字典外的标签键没有点名');
        truthy(str_contains($reject(fn() => $norm('method=get,status=200')), '不在'),
            '拿别的指标的标签串来查也没被拦下');
        truthy(str_contains($reject(fn() => $norm('code=0,operation=a b')), '不合法'),
            '值里的空格没有被收窄校验拦下');
        truthy(str_contains($reject(fn() => $norm('code=0,code=1,operation=x')), '重复'),
            '重复标签没被拦下');
        truthy(str_contains($reject(fn() => $norm('code=0,operation=' . str_repeat('a', 60))), '不合法'),
            '超长标签值没被拦下');
        truthy(str_contains($reject(fn() => $norm('code=0,operation=x,' . str_repeat('a', 200))), '过长'),
            '超长维度串没被拦下');
        truthy(str_contains($reject(fn() => $norm('code=0')), '必须带齐'), '单标签必须拒绝');
    });

check('metric 必须在字典里；字典没声明标签时不编造任何下钻入口',
    function () use ($catalog, $reject): void {
        truthy(StatsDimension::metric('', $catalog) === 'http_requests', '缺省指标不是全站请求量');
        truthy(StatsDimension::metric('API_CALLS', $catalog) === 'api_calls', '指标名没有归一');
        truthy(str_contains($reject(fn() => StatsDimension::metric('nope', $catalog)), '不在指标字典里'),
            '字典外的指标没被拦下');
        truthy(StatsDimension::labelsOf($catalog, 'nope') === null, '未知指标不该返回空标签冒充已知');
        truthy(StatsDimension::labelsOf($catalog, 'db_queries') === [], '无标签指标的声明读错');
        truthy(str_contains(StatsDimension::caveat('db_queries', []), '不编造'),
            '无标签指标没有说明页面不编造入口');
        truthy(StatsDimension::drillDimension('x', ['metrics' => []]) === '',
            '字典没有 api_calls 却生成了维度串');
        truthy(StatsDimension::drillDimension('x', ['metrics' => [
            ['name' => 'api_calls', 'labels' => ['operation', 'code', 'region']],
        ]]) === '', '多出来的标签没法反推，不该硬凑一个查不到的串');
    });

check('下钻链接从真实排行反推完整串，active 唯一，且不编造入口',
    function () use ($catalog, $ops): void {
        $links = StatsDimension::links('24h', 'http_requests', '', $ops, $catalog);
        truthy(count($links) === 3, '全量 + 两个排行项应共 3 项，实际 ' . count($links));
        truthy(!str_contains($links[0]['href'], 'dimension') && $links[0]['active'],
            '第一项应是回到全量且没有维度串');
        foreach (array_slice($links, 1) as $item) {
            truthy(!str_contains($item['href'], ' '), '链接里出现空格，浏览器会截断');
            truthy(str_contains($item['href'], 'dimension='), '下钻链接没有带维度串');
            parse_str((string) parse_url($item['href'], PHP_URL_QUERY), $q);
            $resolved = StatsDimension::normalize((string) ($q['dimension'] ?? ''), $catalog, 'api_calls');
            truthy($resolved['dimension'] === (string) ($q['dimension'] ?? ''),
                '链接里的串不是规范化后的完整串：' . $item['href']);
            truthy(array_keys($resolved['labels']) === ['code', 'operation'],
                '链接少带了标签，精确匹配永远查不到：' . $item['href']);
            truthy(in_array((string) $resolved['labels']['operation'], ['content.posts', 'users.me'], true),
                '链接的 operation 不是从排行里来的');
            truthy((string) ($q['metric'] ?? '') === 'api_calls', '下钻没有切到 api_calls 指标');
        }

        $picked = StatsDimension::links('24h', 'api_calls', 'code=0,operation=users.me', $ops, $catalog);
        $active = array_values(array_filter($picked, static fn(array $i): bool => $i['active']));
        truthy(count($active) === 1 && str_contains($active[0]['href'], 'users.me'),
            '选中维度后 active 不唯一或指错链接');

        $custom = StatsDimension::links('24h', 'http_requests', 'method=get,status=200', $ops, $catalog);
        $last = $custom[count($custom) - 1];
        truthy($last['active'] && str_contains((string) $last['note'], '自定义'),
            '自定义维度没有以当前值出现，用户会被悄悄拨回全量');

        $noDef = StatsDimension::links('24h', 'http_requests', '', $ops, ['metrics' => []]);
        truthy(count($noDef) === 1 && $noDef[0]['active'], '字典缺失时仍编造了假入口');
    });

check('口径说明如实写出精确匹配与真实可命中的示例', function () use ($catalog): void {
    $text = StatsDimension::caveat('api_calls', ['operation', 'code']);
    foreach (['精确匹配', '带齐', 'operation、code', 'code=0,operation=content.posts'] as $needle) {
        truthy(str_contains($text, $needle), '口径说明缺：' . $needle);
    }
    truthy(str_contains($text, '只写 operation=content.posts'), '没有点破契约旧示例命中不了');
});

/** 记录每次 execute 的入参与顺序，证明页面真的把 dimension 交给了接口。 */
$fake = new class implements ApiRegistryInterface {
    /** @var list<array{0:string,1:array<string,mixed>}> */
    public array $calls = [];
    public bool $withPoints = false;
    public function endpoints(): array { return []; }
    public function find(string $id): ?array { return null; }
    public function match(string $method, string $path): ?array { return null; }
    public function registerOperation(string $operation, callable $handler): void {}
    public function execute(string $operation, array $input): mixed
    {
        $this->calls[] = [$operation, $input];
        return match ($operation) {
            'stats.catalog' => ['metrics' => [
                ['name' => 'http_requests', 'labels' => ['method', 'status']],
                ['name' => 'api_calls', 'labels' => ['operation', 'code']],
            ]],
            'stats.overview' => ['traffic' => ['requests' => 7],
                'collector' => ['durable' => true, 'degraded' => false, 'last_error' => '']],
            'stats.series' => [
                'unit' => '次', 'dimension' => (string) ($input['dimension'] ?? ''),
                'points' => $this->withPoints ? [['at' => '2026-11-13 10:00:00', 'value' => 3]] : [],
            ],
            'stats.operations' => ['items' => [['operation' => 'users.me', 'calls' => 3]]],
            default => [],
        };
    }
};

check('collect 把规范化后的 dimension 交给 stats.series，且字典先取',
    function () use ($fake): void {
        $fake->calls = [];
        $q = new StatsQuery($fake);
        $raw = $q->collect('24h', 'api_calls', 'operation=users.me,code=0');
        truthy(($raw['dimension'] ?? '') === 'code=0,operation=users.me', '页面里的维度串不对');
        truthy($fake->calls[0][0] === 'stats.catalog', '字典必须在校验维度之前取');
        $series = null;
        foreach ($fake->calls as [$op, $in]) {
            if ($op === 'stats.series') $series = $in;
        }
        truthy(is_array($series), '页面根本没有调用 stats.series');
        truthy(($series['dimension'] ?? '') === 'code=0,operation=users.me',
            'stats.series 没收到 dimension，等于入口没接上');
        truthy(($series['name'] ?? '') === 'api_calls', '下钻时指标没切到 api_calls');
    });

check('board 呈现当前维度与下钻入口，空采样显式区分于「没流量 / 故障」',
    function () use ($fake): void {
        $fake->withPoints = false;
        $q = new StatsQuery($fake);
        $raw = $q->collect('24h', 'api_calls', 'code=0,operation=users.me');
        $board = $q->board('24h', $raw);
        $ids = array_map(static fn(array $b): string => (string) ($b['id'] ?? ''), $board['blocks']);
        foreach (['stats-dimension-note', 'stats-dimensions', 'stats-dimension-empty'] as $need) {
            truthy(in_array($need, $ids, true), '看板缺少区块：' . $need);
        }
        $flat = json_encode($board['blocks'], JSON_UNESCAPED_UNICODE);
        truthy(str_contains($flat, '精确匹配'), '看板没有说明精确匹配口径');
        truthy(str_contains($flat, '该维度窗口内没有采样'), '空采样没有单独说清');
        truthy(str_contains($flat, '不代表站点没有流量'), '空采样没有与「没流量」区分');
        truthy(str_contains($flat, '不代表采集器故障'), '空采样没有与「故障」区分');
        foreach ($board['blocks'] as $b) {
            if (($b['type'] ?? '') !== 'chart') continue;
            truthy(str_contains((string) $b['title'], 'api_calls'), '图表标题没反映指标');
            truthy(str_contains((string) $b['title'], 'code=0,operation=users.me'), '图表标题没反映维度');
        }

        $fake->withPoints = true;
        $board = $q->board('24h', $q->collect('24h', 'api_calls', 'code=0,operation=users.me'));
        $ids = array_map(static fn(array $b): string => (string) ($b['id'] ?? ''), $board['blocks']);
        truthy(!in_array('stats-dimension-empty', $ids, true), '有采样却仍宣称该维度没有采样');
    });
