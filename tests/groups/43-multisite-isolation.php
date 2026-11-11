<?php
declare(strict_types=1);

use Wx\Core\Http\Request;
use Wx\Systems\Lifecycle\Fs;

/**
 * 43 · 多站点前缀隔离：同一库、两套前缀下的归档与看板。
 *
 * 「表前缀就是隔离手段」这句话此前只在文档里，守卫从没在同一库上同时跑过两个站点。
 * 一旦某条查询忘了带上 db->prefix()，两个站点的文章与统计会悄悄混在一起 ——
 * 单站点测试永远发现不了，因为它们看到的世界只有一套表。
 *
 * 这里让 A / B 共用同一个数据库、不同前缀，各写各的数据，然后只从 HTTP 面取证：
 *  - 归档页只能看到本站文章；跨站术语必须 404（不是「空列表」）；
 *  - 统计看板的请求数各记各的，A 的流量不会出现在 B 的窗口里；
 *  - 物理表名不相交，任何一张表被写成固定前缀都会立刻失败。
 */
echo "\n=== 43. 多站点前缀隔离：归档与看板不串站 ===\n";

$siteA = tempSite('iso-a');
$siteB = tempSite('iso-b');
$prefixA = 'ia_';
$prefixB = 'ib_';
dropTables($prefixA);
dropTables($prefixB);

$opts = ['api' => ['expose_write' => true], 'theme' => ['active' => 'glass-front']];
$kA = isolatedKernel($siteA, $prefixA, $opts);
$kB = isolatedKernel($siteB, $prefixB, $opts);
$kA->boot();
$kB->boot();
$admin = adminHeaders();

/** @return array{0:int, 1:string} */
$hit = static function (object $k, string $path, array $q = [], string $method = 'GET',
    array $body = [], array $h = []): array {
    $r = $k->handle(new Request($method, $path, $q, $h, $body));
    return [$r->status(), $r->body()];
};

/** 各站点的初始文章先移入回收站，让「共几篇」由本夹具独占决定。 */
$seed = static function (object $k, string $prefix, array $posts, bool $withTag) use ($admin, $hit): array {
    $fails = [];
    foreach ([['/api/v1/content/post/delete', ['slug' => 'first-post']],
              ['/api/v1/content/post/delete', ['slug' => 'second-post']]] as [$p, $b]) {
        [$s] = $hit($k, $p, [], 'POST', $b, $admin);
        if ($s !== 200) $fails[] = $prefix . $p . '=' . $s;
    }
    foreach ($posts as $slug => $title) {
        [$s] = $hit($k, '/api/v1/content/posts', [], 'POST',
            ['title' => $title, 'body' => $title . '正文', 'slug' => $slug, 'status' => 'published'], $admin);
        if ($s !== 200) $fails[] = $prefix . $slug . '=' . $s;
    }
    if ($withTag) {
        [$s] = $hit($k, '/api/v1/content/terms/manage', [], 'POST',
            ['action' => 'create', 'kind' => 'tag', 'slug' => 'shared', 'name' => '甲站标签'], $admin);
        if ($s !== 200) $fails[] = $prefix . 'tag=' . $s;
        [$s] = $hit($k, '/api/v1/content/post/terms', [], 'POST',
            ['slug' => 'iso-a1', 'terms' => ['tag:shared']], $admin);
        if ($s !== 200) $fails[] = $prefix . 'bind=' . $s;
    }
    return $fails;
};

$fails = array_merge(
    $seed($kA, $prefixA, ['iso-a1' => '甲站文章一', 'iso-a2' => '甲站文章二'], true),
    $seed($kB, $prefixB, ['iso-b1' => '乙站唯一文章'], false)
);

/** @return list<string> 指定前缀下的物理表名 */
$tables = static function (string $prefix): array {
    global $root;
    $cfg = require $root . '/config/app.php';
    $db = (array) ($cfg['database'] ?? []);
    $pdo = new PDO((string) $db['dsn'], (string) $db['user'], (string) $db['pass']);
    $out = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        if (str_starts_with((string) $t, $prefix)) $out[] = (string) $t;
    }
    sort($out);
    return $out;
};

check('前置：两站各写各的文章，物理表按前缀分流且不相交', function () use ($fails, $tables, $prefixA, $prefixB): void {
    truthy($fails === [], '前置写入失败：' . implode(', ', $fails));
    $a = $tables($prefixA);
    $b = $tables($prefixB);
    truthy($a !== [] && $b !== [], '隔离站点没有建出表');
    truthy(array_intersect($a, $b) === [], '两个前缀共用了同一张物理表');
    truthy(in_array($prefixA . 'content_posts', $a, true), '甲站缺内容表：' . implode(',', $a));
    truthy(in_array($prefixB . 'metric_points', $b, true), '乙站缺统计表：' . implode(',', $b));
});

check('归档隔离：甲站只看到甲站文章，乙站只看到乙站文章', function () use ($kA, $kB, $hit): void {
    [$sa, $ha] = $hit($kA, '/archive');
    [$sb, $hb] = $hit($kB, '/archive');
    truthy($sa === 200 && $sb === 200, '归档页状态异常：' . $sa . '/' . $sb);

    foreach (['甲站文章一', '甲站文章二'] as $t) {
        truthy(str_contains($ha, $t), '甲站归档缺自己的文章：' . $t);
    }
    truthy(!str_contains($ha, '乙站唯一文章'), '甲站归档泄漏了乙站文章');
    truthy(!str_contains($ha, 'iso-b1'), '甲站归档出现了乙站 slug');

    truthy(str_contains($hb, '乙站唯一文章'), '乙站归档缺自己的文章');
    truthy(!str_contains($hb, '甲站文章'), '乙站归档泄漏了甲站文章');
    truthy(!str_contains($hb, 'iso-a1'), '乙站归档出现了甲站 slug');
});

check('术语隔离：甲站的标签在乙站是「不存在」404，不是一片空列表', function () use ($kA, $kB, $hit): void {
    $q = ['kind' => 'tag', 'slug' => 'shared'];
    [$sa, $ha] = $hit($kA, '/term', $q);
    [$sb, $hb] = $hit($kB, '/term', $q);
    truthy($sa === 200, '甲站标签页应 200，实际 ' . $sa);
    truthy(str_contains($ha, '甲站标签'), '甲站标签页没有标签名');
    truthy($sb === 404, '乙站应当 404（术语不存在），实际 ' . $sb);
    truthy(str_contains($hb, '术语不存在'), '乙站 404 没有说明原因');
    truthy(!str_contains($hb, '甲站标签'), '乙站页面泄漏了甲站标签名');
});

check('看板隔离：请求数各记各的，甲站的流量不会出现在乙站窗口里', function () use ($kA, $kB, $hit, $admin): void {
    // 甲站再打 5 次归档，乙站保持不动：两站请求量从此有明确差值。
    for ($i = 0; $i < 5; $i++) $hit($kA, '/archive');
    $hit($kB, '/archive');

    $overview = static function (object $k) use ($hit, $admin): array {
        [$s, $b] = $hit($k, '/api/v1/stats/overview', ['window' => '24h'], 'GET', [], $admin);
        return [$s, (array) (json_decode($b, true)['data'] ?? [])];
    };
    [$sa, $da] = $overview($kA);
    [$sb, $db] = $overview($kB);
    truthy($sa === 200 && $sb === 200, '统计概览状态异常：' . $sa . '/' . $sb);

    $ra = (int) ($da['traffic']['requests'] ?? -1);
    $rb = (int) ($db['traffic']['requests'] ?? -1);
    truthy($ra >= 5, '甲站请求数没有累计：' . $ra);
    truthy($rb >= 1, '乙站请求数没有累计：' . $rb);
    truthy($ra > $rb + 2, '两站请求数没有拉开差距，隔离无法证明：甲 ' . $ra . ' / 乙 ' . $rb);

    // 页面层再看一眼：乙站看板不该出现甲站的文章标题或 slug。
    [$s, $html] = $hit($kB, '/console/stats', ['window' => '24h'], 'GET', [], $admin);
    truthy($s === 200, '乙站看板应 200，实际 ' . $s);
    truthy(!str_contains($html, '甲站文章'), '乙站看板泄漏了甲站文章');
    truthy(str_contains($html, 'data-layout="admin"'), '乙站看板没有使用 admin 布局');
});

dropTables($prefixA);
dropTables($prefixB);
Fs::removeDir($siteA);
Fs::removeDir($siteB);
