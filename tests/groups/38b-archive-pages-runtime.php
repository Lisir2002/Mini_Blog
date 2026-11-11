<?php
declare(strict_types=1);

use Wx\Core\Http\Request;
use Wx\Systems\Lifecycle\Fs;

/**
 * 38b · 归档页运行时：真实 MySQL + 真实主题渲染的端到端行为。
 *
 * 与 38 的分工：38 验纯逻辑与承载边界，这里验「接上独立页装载、接口清单、
 * 权限、主题渲染之后」才显形的错 —— 尤其是 4xx 会不会被 render.layout
 * 甩回 site 槽位、标签云计数与点进去的列表是否同口径、降级是否显式。
 */
echo "\n=== 38b. 归档页运行时：真实数据 / 真实主题 / 状态可判定 ===\n";

$site   = tempSite('archive-rt');
$prefix = 'ar_';
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

// 前置：两篇已发布 + 一篇草稿 + 三个术语，绑定成可区分的两个标签集合。
$writes = [
    // 空库启动会 seed 两篇示例文章；先把它们移入回收站，让「共几篇」由本夹具独占决定。
    ['/api/v1/content/post/delete', ['slug' => 'first-post']],
    ['/api/v1/content/post/delete', ['slug' => 'second-post']],
    ['/api/v1/content/posts',       ['title' => '归档甲', 'body' => '甲正文', 'slug' => 'arch-a', 'status' => 'published']],
    ['/api/v1/content/posts',       ['title' => '归档乙', 'body' => '乙正文', 'slug' => 'arch-b', 'status' => 'published']],
    ['/api/v1/content/posts',       ['title' => '草稿丙', 'body' => '丙正文', 'slug' => 'arch-draft', 'status' => 'draft']],
    ['/api/v1/content/terms/manage', ['action' => 'create', 'kind' => 'tag', 'slug' => 'php', 'name' => 'PHP']],
    ['/api/v1/content/terms/manage', ['action' => 'create', 'kind' => 'tag', 'slug' => 'ops', 'name' => '运维']],
    ['/api/v1/content/terms/manage', ['action' => 'create', 'kind' => 'category', 'slug' => 'notes', 'name' => '随笔']],
    ['/api/v1/content/post/terms',  ['slug' => 'arch-a', 'terms' => ['tag:php', 'category:notes']]],
    ['/api/v1/content/post/terms',  ['slug' => 'arch-b', 'terms' => ['tag:php', 'tag:ops']]],
];
$writeFails = [];
foreach ($writes as [$path, $body]) {
    [$s] = $hit($htmlKernel, $path, [], 'POST', $body, $admin);
    if ($s !== 200) $writeFails[] = $path . '=' . $s;
}

check('前置：文章 / 术语 / 绑定全部写入成功', function () use ($writeFails): void {
    truthy($writeFails === [], '前置写入失败：' . implode(', ', $writeFails));
});

check('标签云计数与点进去的列表条数同口径', function () use ($json): void {
    [, , $b] = $json('/terms');
    $d = json_decode($b, true);
    $terms = (array) ($d['terms'] ?? []);
    $bySlug = [];
    foreach ($terms as $t) $bySlug[(string) ($t['slug'] ?? '')] = (int) ($t['count'] ?? -1);
    truthy(count($terms) === 3, '术语数不符，实际 ' . count($terms));
    truthy(($bySlug['php'] ?? -1) === 2, 'php 的标签云计数应为 2');
    truthy(($bySlug['ops'] ?? -1) === 1, 'ops 的标签云计数应为 1');
    foreach (['php' => 2, 'ops' => 1] as $slug => $expected) {
        [, , $tb] = $json('/term', ['kind' => 'tag', 'slug' => $slug]);
        $td = json_decode($tb, true);
        truthy((int) ($td['total'] ?? -1) === $expected, $slug . ' 列表总数与标签云计数不一致');
    }
});

check('归档页渲染真实文章、年月分组与术语链接，草稿不混入', function () use ($html): void {
    [$s, $ct, $b] = $html('/archive');
    truthy($s === 200 && str_contains($ct, 'text/html'), '归档未输出 HTML：' . $s . ' ' . $ct);
    truthy(str_contains($b, 'data-layout="archive"'), '未使用 archive 布局');
    truthy(str_contains($b, 'data-view="archive"') && str_contains($b, 'data-total="2"'), '视图或总数标记不符');
    truthy(str_contains($b, '归档甲') && str_contains($b, '归档乙'), '已发布文章未出现在归档');
    truthy(!str_contains($b, '草稿丙'), '草稿混进了公开归档');
    truthy(str_contains($b, '2026 年') && str_contains($b, '月'), '缺少年月分组标题');
    truthy(str_contains($b, '/post?slug=arch-a'), '文章链接不是由主题按 slug 渲染的');
    truthy(str_contains($b, 'slug=php') && str_contains($b, 'slug=ops'), '文章上的术语链接缺失');
    truthy(str_contains($b, 'data-empty="0"') && str_contains($b, 'data-truncated="0"'), '空 / 截断标记缺失');
});

check('标签云页与术语详情页按筛选条件渲染，不串数据', function () use ($html): void {
    [$s, , $b] = $html('/terms');
    truthy($s === 200 && str_contains($b, 'data-view="terms"'), '标签云页视图标记不符');
    truthy(str_contains($b, 'PHP') && str_contains($b, '随笔'), '标签云缺少术语名称');

    [, , $php] = $html('/term', ['kind' => 'tag', 'slug' => 'php']);
    truthy(str_contains($php, 'data-view="term"') && str_contains($php, 'data-total="2"'), 'php 详情总数不符');
    truthy(str_contains($php, '归档甲') && str_contains($php, '归档乙'), 'php 详情漏文章');

    [, , $ops] = $html('/term', ['kind' => 'tag', 'slug' => 'ops']);
    truthy(str_contains($ops, 'data-total="1"'), 'ops 详情总数不符');
    truthy(str_contains($ops, '归档乙') && !str_contains($ops, '归档甲'), 'ops 详情串进了别的文章');
});

check('未知术语返回 404 且用 archive 布局显式说明，不伪装成空列表', function () use ($html, $json): void {
    [$s, $ct, $b] = $html('/term', ['kind' => 'tag', 'slug' => 'nope']);
    truthy($s === 404, '未知术语应返回 404，实际 ' . $s);
    truthy(str_contains($ct, 'text/html'), '失败态不应退化成白屏或纯 JSON');
    truthy(str_contains($b, 'data-layout="archive"'), '4xx 被 render.layout 甩回了 site 槽位');
    truthy(str_contains($b, 'data-failed="1"') && str_contains($b, '术语不存在'), '没有显式说明「术语不存在」');
    [, , $jb] = $json('/term', ['kind' => 'tag', 'slug' => 'nope']);
    $d = json_decode($jb, true);
    truthy(($d['state']['phase'] ?? '') === 'failed', '结构化失败态缺失');
    truthy(($d['empty'] ?? null) === false, '未知术语不该被标成「空结果」');
});

check('非法 kind / 空 slug 返回 422，且同样留在 archive 布局', function () use ($html): void {
    foreach ([['kind' => 'bad', 'slug' => 'php'], ['kind' => 'tag', 'slug' => '']] as $q) {
        [$s, , $b] = $html('/term', $q);
        truthy($s === 422, '参数不合法应返回 422，实际 ' . $s);
        truthy(str_contains($b, '参数不合法') && str_contains($b, 'data-layout="archive"'),
            '参数错误页缺少显式说明或布局错误');
    }
});

check('Headless（无主题）退化为纯 JSON，且状态字段完整', function () use ($json): void {
    [$s, $ct, $b] = $json('/archive');
    truthy($s === 200 && str_contains($ct, 'json'), '无主题时未退化成纯数据');
    $d = json_decode($b, true);
    foreach (['view', 'sections', 'terms', 'total', 'shown', 'truncated', 'empty', 'terms_state', 'state', 'links'] as $k) {
        truthy(array_key_exists($k, $d), '结构化 payload 缺字段：' . $k);
    }
    truthy((int) ($d['total'] ?? -1) === 2 && $d['truncated'] === false, '总数或截断标记不符');
    truthy(!str_contains(strtolower($b), '<html'), '纯数据响应里出现 HTML');
});

check('不提供 archive 槽位的主题显式退化为纯 JSON，不是白屏', function () use ($root): void {
    $k = new \Wx\Core\Kernel($root, ['theme' => ['active' => 'bare']]);
    $k->boot();
    $r = $k->handle(new Request('GET', '/archive', [], []));
    truthy(str_contains($r->contentType(), 'json'), 'Bare 未退化为纯 JSON');
    truthy(!str_contains(strtolower($r->body()), '<html'), '退化响应里混入了 HTML');
});

check('内存降级：标签云显式不可用，归档主体仍可读，不伪装成空标签', function () use ($root): void {
    $k = new \Wx\Core\Kernel($root, [
        'database' => ['dsn' => ''],
        'theme'    => ['active' => 'glass-front'],
    ]);
    $k->boot();
    $archive = $k->handle(new Request('GET', '/archive', [], []));
    truthy($archive->status() === 200, '文章归档不该被旁路能力拖垮');
    truthy(str_contains($archive->body(), 'data-terms-state="1"'), '标签云失败没有显式标记');
    truthy(str_contains($archive->body(), '标签云不可用'), '降级文案缺失');

    $terms = $k->handle(new Request('GET', '/terms', [], []));
    truthy($terms->status() === 503, '功能不可用不该返回 200，实际 ' . $terms->status());
    truthy(str_contains($terms->body(), '内存降级'), '未说清是部署形态而非偶发故障');
    truthy(str_contains($terms->body(), 'data-layout="archive"'), '降级页布局被甩回 site');
});

dropTables($prefix);
Fs::removeDir($site);
