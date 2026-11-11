<?php
declare(strict_types=1);

use Wx\Core\Http\Request;
use Wx\Systems\Lifecycle\Fs;

/**
 * 40b · 统计看板运行时：真实权限 + 真实主题 + 真实采集器。
 *
 * 验的是「页面上能不能看见真相」：
 *  - 匿名访问被拒且不渲染任何看板内容；
 *  - admin 看到的是 admin 布局下的 notice + chart，不是一排无标注的 0；
 *  - 非法窗口 422 且不渲染数据；
 *  - 数据库不可用时权限层 fail-closed，页面宁可不给数字，也不显示一排列全 0；
 *  - Headless 退化为纯数据。
 *
 * 「采集器降级」的文案与三态判定由 40 覆盖：构造那一刻需要替换 MetricStoreInterface，
 * 而容器不允许低优先级覆盖已注册的高优先级实现，硬绕会把守卫本身变得脆弱。
 */
echo "\n=== 40b. 统计看板运行时：权限 / 渲染 / 拒绝假数据 ===\n";

$site   = tempSite('stats-board');
$prefix = 'sb_';
dropTables($prefix);

$adminKernel = isolatedKernel($site, $prefix, ['theme' => ['active' => 'glass']]);
$adminKernel->boot();
$jsonKernel = isolatedKernel($site, $prefix, []);
$jsonKernel->boot();
$admin = adminHeaders();

/** @return array{0:int, 1:string, 2:string} */
$call = static function (object $k, string $path, array $q = [], array $h = []): array {
    $r = $k->handle(new Request('GET', $path, $q, $h));
    return [$r->status(), $r->contentType(), $r->body()];
};
$html = static fn(string $path, array $q = [], array $h = []): array => $call($adminKernel, $path, $q, $h);
$json = static fn(string $path, array $q = [], array $h = []): array => $call($jsonKernel, $path, $q, $h);

check('匿名访问被拒：不渲染任何看板内容，也不泄露区块结构', function () use ($html): void {
    [$s, $ct, $b] = $html('/console/stats');
    truthy(in_array($s, [401, 403], true), '匿名访问应被拒绝，实际 ' . $s);
    truthy(!str_contains($b, 'data-stats-state'), '拒绝态却渲染了看板状态标记');
    truthy(!str_contains($b, 'data-chart-points'), '拒绝态却渲染了图表区块');
    truthy(str_contains($ct, 'html') || str_contains($ct, 'json'), '拒绝态没有可读响应');
});

check('admin 看到 admin 布局下的 notice 与 chart，状态可被文本证据区分', function () use ($html, $admin): void {
    [$s, $ct, $b] = $html('/console/stats', [], $admin);
    truthy($s === 200, '管理员应 200，实际 ' . $s);
    truthy(str_contains($ct, 'text/html'), '看板未输出 HTML');
    truthy(str_contains($b, 'data-layout="admin"'), '看板没有复用 admin 布局');

    // 状态标记与图表标记必须同时出现：一个回答「数据可不可信」，一个回答「有没有采样」。
    truthy(str_contains($b, 'data-stats-state="'), '缺少采集器状态标记');
    truthy(str_contains($b, 'data-chart-points="'), '缺少图表区块');
    truthy(str_contains($b, '采集器与字典'), '缺少采集器说明区块');
    truthy(str_contains($b, '持久化'), '采集器区块没有说明是否落库');
    truthy(str_contains($b, '24h'), '页面没有标注默认窗口');
});

check('非法窗口 422，留在 admin 布局并点名字段，且不渲染数据', function () use ($html, $admin): void {
    foreach (['99x', '0h', '31d'] as $w) {
        [$s, , $b] = $html('/console/stats', ['window' => $w], $admin);
        truthy($s === 422, '窗口 ' . $w . ' 应 422，实际 ' . $s);
        truthy(str_contains($b, 'data-layout="admin"'), '422 被甩离 admin 布局');
        truthy(str_contains($b, 'window'), '错误文案没有点名字段');
        truthy(!str_contains($b, 'data-chart-points'), '参数错误时不该渲染看板数据');
    }
});

check('合法窗口原样生效：7d 不会悄悄变成 24h', function () use ($html, $admin): void {
    [$s, , $b] = $html('/console/stats', ['window' => '7d'], $admin);
    truthy($s === 200, '7d 应 200，实际 ' . $s);
    truthy(str_contains($b, '7d'), '页面没有反映请求的窗口');

    // 窗口切换真的可点：链接、active 迁移、aria-current 三件事同时成立。
    truthy(str_contains($b, 'class="glass-links"'), '页面没有渲染窗口链接组');
    truthy(str_contains($b, 'href="/console/stats?window=24h"'), '缺少切回 24h 的链接');
    truthy(str_contains($b, 'href="/console/stats?window=30d"'), '缺少切到 30d 的链接');
    truthy(substr_count($b, 'aria-current="page"') === 1, 'active 项不唯一');
    $pos = strpos($b, 'href="/console/stats?window=7d"');
    truthy($pos !== false && str_contains(substr($b, max(0, $pos - 200), 200), 'is-active'),
        '当前窗口 7d 没有被标成 active');
});

check('Headless 退化为纯数据：状态、区块、窗口字段齐全且不含 HTML', function () use ($json, $admin): void {
    [$s, $ct, $b] = $json('/console/stats', ['window' => '24h'], $admin);
    truthy($s === 200, '无主题时应 200，实际 ' . $s);
    truthy(str_contains($ct, 'json'), '无主题时未退化为纯数据');
    $d = json_decode($b, true);
    foreach (['window', 'stats_state', 'collector', 'blocks', 'state'] as $k) {
        truthy(array_key_exists($k, $d), '结构化 payload 缺字段：' . $k);
    }
    truthy(($d['window'] ?? '') === '24h', '窗口字段不符');
    truthy(!str_contains(strtolower($b), '<html'), '纯数据响应里出现 HTML');
    truthy(is_array($d['blocks'] ?? null) && $d['blocks'] !== [], '纯数据里没有区块');
});

check('数据库不可用：权限层 fail-closed，宁可不给数字也不显示一排列全 0', function () use ($root, $admin): void {
    $k = new \Wx\Core\Kernel($root, [
        'database' => ['dsn' => ''],
        'theme'    => ['active' => 'glass'],
    ]);
    $k->boot();
    $r = $k->handle(new Request('GET', '/console/stats', [], $admin));
    $b = $r->body();
    truthy(in_array($r->status(), [401, 403, 503], true),
        '库不可用时应显式拒绝，实际 ' . $r->status());
    truthy(!str_contains($b, 'data-chart-points'), '库不可用却渲染了图表');
    truthy(!str_contains($b, 'data-stats-state="ok"'), '库不可用却标记成健康');
});

check('控制台能发现看板入口，不是只有知道 URL 才进得去', function () use ($root): void {
    $dash = (string) file_get_contents($root . '/themes/Glass/templates/partials/dashboard.php');
    truthy(str_contains($dash, '/console/stats'), 'Glass 仪表盘没有看板入口');

    $front = (string) file_get_contents($root . '/themes/GlassFront/templates/admin.php');
    truthy(str_contains($front, 'data-view="stats"') || str_contains($front, '/console/stats'),
        'GlassFront 的 admin 布局没有看板标记或入口');
});

dropTables($prefix);
Fs::removeDir($site);
