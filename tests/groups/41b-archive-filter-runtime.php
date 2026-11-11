<?php
declare(strict_types=1);

use Wx\Core\Http\Request;
use Wx\Systems\Lifecycle\Fs;

/**
 * 41b · 归档筛选与分页运行时：真实 MySQL + 真实主题。
 *
 * 与 41 的分工：41 把参数边界与过滤口径逐值穷举；这里只验「接上独立页、
 * 接口清单、主题渲染之后」才显形的错 —— 参数能不能传通、命中数与全站数
 * 会不会混成一个、越界页会不会被当成没有文章、术语条件翻页后还在不在。
 *
 * 刻意不改 created_at：那是审计字段，接口不开放；跨年跨月的组合由 41 覆盖，
 * 这里用「当前年命中 / 2020 年 0 命中」证明筛选真的在收窄，且两个口径分离。
 */
echo "\n=== 41b. 归档筛选与分页运行时：参数传通 / 口径分离 / 越界显式 ===\n";

$site   = tempSite('archive-filter');
$prefix = 'af_';
dropTables($prefix);

$htmlKernel = isolatedKernel($site, $prefix, [
    'api'   => ['expose_write' => true],
    'theme' => ['active' => 'glass-front'],
]);
$htmlKernel->boot();
$jsonKernel = isolatedKernel($site, $prefix, ['api' => ['expose_write' => true]]);
$jsonKernel->boot();
$admin = adminHeaders();

/** @return array{0:int, 1:string, 2:string} */
$hit = static function (object $k, string $path, array $q = [], string $method = 'GET',
    array $body = [], array $h = []): array {
    $r = $k->handle(new Request($method, $path, $q, $h, [], json_encode($body)));
    return [$r->status(), $r->contentType(), $r->body()];
};
$html = static fn(string $path, array $q = []): array => $hit($htmlKernel, $path, $q);
$json = static fn(string $path, array $q = []): array => $hit($jsonKernel, $path, $q);

$writes = [
    // 空库启动会 seed 两篇示例文章；先移入回收站，让「共几篇」由本夹具独占决定。
    ['/api/v1/content/post/delete', ['slug' => 'first-post']],
    ['/api/v1/content/post/delete', ['slug' => 'second-post']],
    ['/api/v1/content/posts',       ['title' => '筛选甲', 'body' => '甲正文', 'slug' => 'af-a', 'status' => 'published']],
    ['/api/v1/content/posts',       ['title' => '筛选乙', 'body' => '乙正文', 'slug' => 'af-b', 'status' => 'published']],
    ['/api/v1/content/posts',       ['title' => '草稿丙', 'body' => '丙正文', 'slug' => 'af-draft', 'status' => 'draft']],
    ['/api/v1/content/terms/manage', ['action' => 'create', 'kind' => 'tag', 'slug' => 'php', 'name' => 'PHP']],
    ['/api/v1/content/terms/manage', ['action' => 'create', 'kind' => 'category', 'slug' => 'notes', 'name' => '随笔']],
    ['/api/v1/content/post/terms',  ['slug' => 'af-a', 'terms' => ['tag:php', 'category:notes']]],
    ['/api/v1/content/post/terms',  ['slug' => 'af-b', 'terms' => ['tag:php']]],
];
$writeFails = [];
foreach ($writes as [$path, $body]) {
    [$s] = $hit($htmlKernel, $path, [], 'POST', $body, $admin);
    if ($s !== 200) $writeFails[] = $path . '=' . $s;
}

check('前置：两篇已发布 + 一篇草稿 + 两个术语全部写入成功', function () use ($writeFails): void {
    truthy($writeFails === [], '前置写入失败：' . implode(', ', $writeFails));
});

check('分页：每页 1 篇时共 2 页，两页内容互补，越界页显式 beyond 而不是 404', function () use ($html, $json): void {
    [, , $b1] = $html('/archive', ['per_page' => '1', 'page' => '1']);
    truthy(str_contains($b1, 'data-page="1"') && str_contains($b1, 'data-pages="2"'), '首页分页标记不符');
    truthy(str_contains($b1, 'rel="next"') && !str_contains($b1, 'rel="prev"'), '首页不该有上一页');

    [, , $b2] = $html('/archive', ['per_page' => '1', 'page' => '2']);
    truthy(str_contains($b2, 'data-page="2"') && str_contains($b2, 'rel="prev"'), '第二页缺上一页');
    truthy(!str_contains($b2, 'rel="next"'), '最后一页不该有下一页');

    // 分页不能把同一页再渲染一次：两篇文章必须互补地分布。
    $a1 = str_contains($b1, '筛选甲');
    $b1has = str_contains($b1, '筛选乙');
    $a2 = str_contains($b2, '筛选甲');
    $b2has = str_contains($b2, '筛选乙');
    truthy(($a1 xor $b1has) && ($a2 xor $b2has) && ($a1 !== $a2), '分页没有把文章拆到不同页');

    [$s, , $b9] = $html('/archive', ['page' => '99']);
    truthy($s === 200, '越界页是合法状态，不该 404，实际 ' . $s);
    truthy(str_contains($b9, 'data-beyond="1"'), '越界页没有显式标记 beyond');
    truthy(str_contains($b9, '页码越界'), '越界文案没有说明「不是没有文章」');
    // 只有提示没有出口等于把读者困在错误页上。
    truthy(str_contains($b9, 'data-pager="1"'), '越界页没有分页导航');
    truthy(str_contains($b9, '回到第'), '越界页没有「回到最后一页」的链接');

    [, , $jb] = $json('/archive', ['page' => '99']);
    $jd = json_decode($jb, true);
    truthy(($jd['beyond'] ?? null) === true, '结构化 payload 的 beyond 标记缺失');
});

check('口径分离：命中数用 total，全站数用 site_total；0 命中是筛选收窄而非站点为空', function () use ($html, $json): void {
    $year = date('Y');
    [, , $b] = $html('/archive', ['year' => $year]);
    truthy(str_contains($b, 'data-filter="1"'), '筛选激活标记缺失');
    truthy(str_contains($b, 'data-total="2"') && str_contains($b, 'data-site-total="2"'), '命中数与全站数不符');
    truthy(str_contains($b, 'data-empty="0"'), '有命中却被标成空');

    [$s, , $z] = $html('/archive', ['year' => '2020']);
    truthy($s === 200, '无命中年份应返回 200，实际 ' . $s);
    truthy(str_contains($z, 'data-total="0"'), '过滤后命中数应为 0');
    truthy(str_contains($z, 'data-site-total="2"'), '过滤后丢了全站总数');
    truthy(str_contains($z, 'data-empty="1"'), '0 命中应标记 empty');
    truthy(str_contains($z, '当前时间范围内没有已发布文章'), '空态文案没有区分「筛选收窄」与「站点为空」');

    [, , $jb] = $json('/archive', ['year' => '2020']);
    $d = json_decode($jb, true);
    truthy((int) ($d['total'] ?? -1) === 0, '结构化 payload 的命中数不符');
    truthy((int) ($d['site_total'] ?? -1) === 2, '结构化 payload 的全站数不符');
    truthy((int) ($d['pages'] ?? -1) === 1, '空结果页数应为 1');
});

check('非法筛选参数 422，留在 archive 布局并点名字段', function () use ($html): void {
    $cases = [
        ['year' => 'abc'], ['month' => '13'], ['page' => '0'],
        ['per_page' => '99'], ['year' => '1800'],
    ];
    foreach ($cases as $q) {
        [$s, , $b] = $html('/archive', $q);
        truthy($s === 422, json_encode($q) . ' 应 422，实际 ' . $s);
        truthy(str_contains($b, '筛选参数不合法'), json_encode($q) . ' 缺少显式说明');
        truthy(str_contains($b, 'data-layout="archive"'), json_encode($q) . ' 的 422 被甩回 site 槽位');
        truthy(str_contains($b, (string) array_key_first($q)), json_encode($q) . ' 的文案没有点名字段');
    }
});

check('术语页的筛选与翻页保留 kind / slug，条件不丢', function () use ($html): void {
    [, , $b] = $html('/term', ['kind' => 'tag', 'slug' => 'php', 'per_page' => '1']);
    truthy(str_contains($b, 'name="kind" value="tag"') && str_contains($b, 'name="slug" value="php"'),
        '筛选表单丢了术语条件');
    truthy(str_contains($b, 'kind=tag') && str_contains($b, 'slug=php'), '翻页链接丢了术语条件');
    truthy(str_contains($b, 'data-pages="2"'), 'php 下两篇文章按每页 1 篇应分两页');

    [, , $z] = $html('/term', ['kind' => 'tag', 'slug' => 'php', 'year' => '2020']);
    truthy(str_contains($z, 'data-total="0"') && str_contains($z, 'data-site-total="2"'),
        '术语页的筛选口径与全站口径混用了');
    truthy(str_contains($z, 'kind=tag') && str_contains($z, 'slug=php'), '筛选后翻页链接丢了术语条件');
});

check('Headless payload 含完整筛选分页字段，且不出现 HTML', function () use ($json): void {
    [, , $b] = $json('/archive', ['per_page' => '1', 'page' => '2']);
    $d = json_decode($b, true);
    foreach (['page_no', 'pages', 'per_page', 'has_prev', 'has_next', 'beyond',
              'filter', 'filter_action', 'site_total', 'filter_incomplete',
              'prev_href', 'next_href'] as $k) {
        truthy(array_key_exists($k, $d), 'payload 缺字段：' . $k);
    }
    truthy((int) ($d['page_no'] ?? 0) === 2 && (int) ($d['pages'] ?? 0) === 2, '分页字段值不符');
    truthy(($d['prev_href'] ?? '') !== '' && ($d['next_href'] ?? '') === '', 'prev / next 链接不符');
    truthy(str_contains((string) $d['prev_href'], 'per_page=1'), 'prev 链接没保留 per_page');
    truthy(!str_contains(strtolower($b), '<html'), '纯 JSON 里出现 HTML');
});

check('内存降级：带筛选时归档主体仍可读，术语页显式 503，不伪装成空', function () use ($root): void {
    $k = new \Wx\Core\Kernel($root, [
        'database' => ['dsn' => ''],
        'theme'    => ['active' => 'glass-front'],
    ]);
    $k->boot();

    $archive = $k->handle(new Request('GET', '/archive', ['year' => (string) date('Y')], []));
    truthy($archive->status() === 200, '归档主体不该被旁路能力拖垮');
    truthy(str_contains($archive->body(), 'data-terms-state="1"'), '标签云降级没有显式标记');

    $terms = $k->handle(new Request('GET', '/terms', [], []));
    truthy($terms->status() === 503, '术语页应显式 503，实际 ' . $terms->status());
    truthy(str_contains($terms->body(), '内存降级'), '未说清是部署形态');
});

dropTables($prefix);
Fs::removeDir($site);
