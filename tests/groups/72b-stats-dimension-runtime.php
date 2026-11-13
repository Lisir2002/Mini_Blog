<?php
declare(strict_types=1);

use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Http\Request;
use Wx\Systems\Lifecycle\Fs;

/**
 * 72b · 维度下钻运行时闭环：真实 HTTP + 真实 MariaDB + 真实主题。
 *
 * 纯函数只能证明「串拼对了」，证明不了「串真的能命中」。本组先发真实接口
 * 请求让采集器落下 api_calls 的 code=0,operation=... 采样，再从 stats.operations
 * 排行反推维度串，验证看板确实取到采样点；同时锁住非法维度 422、
 * 合法但无采样的维度不伪装成「站点没流量 / 采集挂了」，以及 GET 链接可分享。
 */
echo "\n=== 72b. 维度下钻运行时：真实采样 / 可命中下钻 / 拒绝假入口 ===\n";

$site   = tempSite('stats-dimension');
$prefix = 'sd_';
dropTables($prefix);
$k = isolatedKernel($site, $prefix, ['theme' => ['active' => 'glass']]);
$k->boot();
$jsonKernel = isolatedKernel($site, $prefix, []);
$jsonKernel->boot();
$admin = adminHeaders();

/** @return array{0:int, 1:string, 2:string} */
$call = static function (object $kernel, string $path, array $q = [], array $h = []): array {
    $r = $kernel->handle(new Request('GET', $path, $q, $h));
    return [$r->status(), $r->contentType(), $r->body()];
};
$html = static fn(string $path, array $q = [], array $h = []): array => $call($k, $path, $q, $h);
$json = static fn(string $path, array $q = [], array $h = []): array => $call($jsonKernel, $path, $q, $h);

// 三次真实接口调用：api.executed 会被自动埋点成 api_calls 的 code=0 采样，
// kernel.shutdown 再 flush 落库。下面所有断言都基于这份真实数据，不造内存假数。
for ($i = 0; $i < 3; $i++) {
    [$s] = $html('/api/v1/users/me', [], $admin);
    truthy($s === 200, '采样用的接口请求失败，实际 ' . $s);
}

/** @var ApiRegistryInterface $reg */
$reg = $k->container()->resolve(ApiRegistryInterface::class);
$catalog = (array) $reg->execute('stats.catalog', []);
$ops = (array) $reg->execute('stats.operations', ['window' => '24h', 'limit' => 12]);
$top = (array) ($ops['items'][0] ?? []);
$operation = (string) ($top['operation'] ?? '');
$dimension = 'code=0,operation=' . $operation;

check('真实采集：字典声明双标签，排行给出真实 operation，采样确实落库',
    function () use ($catalog, $ops, $top, $operation, $reg, $dimension): void {
        truthy($operation === 'users.me', '排行里的 operation 不是刚才真实调用的接口：' . $operation);
        $labels = null;
        foreach ((array) ($catalog['metrics'] ?? []) as $def) {
            if ((string) ($def['name'] ?? '') === 'api_calls') $labels = (array) ($def['labels'] ?? []);
        }
        truthy(is_array($labels) && in_array('code', $labels, true)
            && in_array('operation', $labels, true), 'api_calls 的字典没有声明 code + operation');
        truthy((int) ($top['calls'] ?? 0) >= 3, '真实调用次数没有被统计到');
        $series = (array) $reg->execute('stats.series',
            ['name' => 'api_calls', 'dimension' => $dimension, 'window' => '24h', 'limit' => 48]);
        truthy(count((array) ($series['points'] ?? [])) > 0,
            '完整维度串竟然命中不到任何采样点，入口就是假的');
        truthy(($series['dimension'] ?? '') === $dimension, '后端回显的维度串与请求不一致');
    });

check('页面下钻：200、图表有采样点、当前维度与口径说明都可见',
    function () use ($html, $json, $admin, $dimension): void {
        [$s, $ct, $b] = $html('/console/stats',
            ['metric' => 'api_calls', 'dimension' => $dimension], $admin);
        truthy($s === 200, '下钻页面应 200，实际 ' . $s);
        truthy(str_contains($ct, 'text/html'), '带主题时没输出 HTML');
        truthy(str_contains($b, 'data-layout="admin"'), '下钻页被甩离 admin 布局');
        truthy(str_contains($b, $dimension), '页面没有回显当前维度');
        truthy(str_contains($b, 'id="stats-dimensions"'), '页面没有下钻链接组');
        truthy(str_contains($b, '精确匹配'), '页面没有说明精确匹配口径');
        truthy(str_contains($b, 'data-chart-points="')
            && !str_contains($b, 'data-chart-points="0"'),
            '下钻后图表没有采样点，等于入口不命中');

        // GET 可分享：链接里的维度串必须能被直接复制后再请求一次。
        truthy(str_contains($b, rawurlencode($dimension)), '下钻链接里没有可复制的维度串');
        [$s2, , $b2] = $html('/console/stats',
            ['window' => '24h', 'metric' => 'api_calls', 'dimension' => $dimension], $admin);
        truthy($s2 === 200 && str_contains($b2, $dimension), '分享出去的链接复现不出同一维度');
    });

check('Headless 退化为纯数据：metric / dimension 字段齐全且不含 HTML',
    function () use ($json, $admin, $dimension): void {
        [$s, $ct, $b] = $json('/console/stats',
            ['metric' => 'api_calls', 'dimension' => $dimension], $admin);
        truthy($s === 200 && str_contains($ct, 'json'), '无主题时没有退化为纯数据');
        $d = json_decode($b, true);
        foreach (['window', 'stats_state', 'collector', 'blocks', 'state', 'metric', 'dimension'] as $k) {
            truthy(array_key_exists($k, (array) $d), '结构化 payload 缺字段：' . $k);
        }
        truthy(($d['dimension'] ?? '') === $dimension, '纯数据里的维度串不符');
        truthy(($d['metric'] ?? '') === 'api_calls', '纯数据里的指标不符');
        truthy(!str_contains(strtolower($b), '<html'), '纯数据响应里出现 HTML');
    });

check('缺标签 / 非法键 / 未知指标一律 422，且绝不渲染图表',
    function () use ($html, $admin): void {
        $bad = [
            ['metric' => 'api_calls', 'dimension' => 'operation=users.me'],
            ['metric' => 'api_calls', 'dimension' => 'foo=bar,code=0,operation=users.me'],
            ['metric' => 'api_calls', 'dimension' => 'code=0,operation=a b'],
            ['metric' => 'nope', 'dimension' => ''],
        ];
        foreach ($bad as $q) {
            [$s, , $b] = $html('/console/stats', $q, $admin);
            truthy($s === 422, '非法参数 ' . json_encode($q, JSON_UNESCAPED_UNICODE)
                . ' 应 422，实际 ' . $s);
            truthy(str_contains($b, 'data-layout="admin"'), '422 被甩离 admin 布局');
            truthy(!str_contains($b, 'data-chart-points'), '参数错误时不该渲染看板图表');
            truthy(str_contains($b, 'window'), '错误文案没有点名字段');
        }
    });

check('合法但无采样的维度：明说「该维度没有采样」，不冒充没流量或故障',
    function () use ($html, $admin): void {
        [$s, , $b] = $html('/console/stats',
            ['metric' => 'api_calls', 'dimension' => 'code=0,operation=never.happened'], $admin);
        truthy($s === 200, '格式合法的维度应 200，实际 ' . $s);
        truthy(str_contains($b, '该维度窗口内没有采样'), '没有单独说明该维度无采样');
        truthy(str_contains($b, '不代表站点没有流量'), '没有与「站点没流量」区分');
        truthy(str_contains($b, '不代表采集器故障'), '没有与「采集器故障」区分');
        truthy(str_contains($b, 'code=0,operation=never.happened'), '页面把用户选的维度悄悄改掉了');
        truthy(str_contains($b, '全部（不分维度）'), '没有给出回到全量的出口');
    });

dropTables($prefix);
Fs::removeDir($site);
