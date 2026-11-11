<?php
declare(strict_types=1);

use Wx\Core\Http\Request;
use Wx\Systems\Lifecycle\Fs;

/**
 * 45b · 站内搜索运行时：真实库 + 真实主题
 *
 * 从 HTTP 面取证四件事：
 *  1. 未带 q：200 + idle 引导（不是「没有结果」）；
 *  2. 命中：200 + ok + 文章链接；
 *  3. 未命中：200 + empty，文案与 idle 不同；
 *  4. % 按字面量匹配：不会因为通配符返回全站；
 *  5. 超长关键词 422，不静默截断。
 */
echo "\n=== 45b. 站内搜索运行时：四态与字面量匹配 ===\n";

$site = tempSite('search');
$prefix = 'is_';
dropTables($prefix);
$k = isolatedKernel($site, $prefix, ['theme' => ['active' => 'glass'], 'api' => ['expose_write' => true]]);
$k->boot();
$admin = adminHeaders();

/** @return array{0:int, 1:string} */
$hit = static function (string $path, array $q = [], array $h = []) use ($k): array {
    $r = $k->handle(new Request('GET', $path, $q, $h));
    return [$r->status(), $r->body()];
};
/** @return int */
$post = static function (string $path, array $body) use ($k, $admin): int {
    return $k->handle(new Request('POST', $path, [], $admin, $body))->status();
};

// 夹具：站内默认两篇文章先移入回收站，避免默认内容干扰搜索计数。
foreach (['first-post', 'second-post'] as $slug) {
    $post('/api/v1/content/post/delete', ['slug' => $slug]);
}
truthy($post('/api/v1/content/posts', [
    'title' => '玻璃拟态实践', 'body' => '这是一篇关于磨砂玻璃质感的正文', 'slug' => 's-glass', 'status' => 'published',
]) === 200, '造文章失败');
truthy($post('/api/v1/content/posts', [
    'title' => '折扣 50% 的说明', 'body' => '百分号不是通配符', 'slug' => 's-percent', 'status' => 'published',
]) === 200, '造百分号文章失败');

check('未带 q：200 + idle 引导，且不是「没有匹配」', function () use ($hit): void {
    [$s, $b] = $hit('/search');
    truthy($s === 200, '未带 q 应 200，实际 ' . $s);
    truthy(str_contains($b, 'data-stats-state="idle"'), '未渲染 idle 态');
    truthy(str_contains($b, '开始搜索'), '没有搜索引导');
    truthy(!str_contains($b, '没有匹配'), '把「未输入」说成了「没有匹配」');
});

check('命中：200 + ok 态，结果里带文章链接', function () use ($hit): void {
    [$s, $b] = $hit('/search', ['q' => '磨砂玻璃']);
    truthy($s === 200, '搜索应 200，实际 ' . $s);
    truthy(str_contains($b, 'data-stats-state="ok"'), '没有渲染 ok 态');
    truthy(str_contains($b, '玻璃拟态实践'), '结果里没有命中的标题');
    truthy(str_contains($b, '/post?slug=s-glass'), '结果没有指向文章页');
    truthy(str_contains($b, '共 1 篇命中'), '没有写明命中数');
});

check('未命中：200 + empty 态，文案与 idle 不同', function () use ($hit): void {
    [$s, $b] = $hit('/search', ['q' => '没有任何文章包含这串字符']);
    truthy($s === 200, '未命中应 200，实际 ' . $s);
    truthy(str_contains($b, 'data-stats-state="empty"'), '没有渲染 empty 态');
    truthy(str_contains($b, '没有匹配'), '没有明确说明没有匹配');
    truthy(str_contains($b, '/archive'), '没有给出归档出口');
});

check('% 与 _ 按字面量匹配：不会返回全站', function () use ($hit): void {
    [$s, $b] = $hit('/search', ['q' => '%']);
    truthy($s === 200, '搜 % 应 200，实际 ' . $s);
    truthy(str_contains($b, '共 1 篇命中'), '百分号被当成通配符（返回了多篇）：' . substr($b, 0, 80));
    truthy(str_contains($b, '折扣 50%'), '没有命中真正包含百分号的文章');

    [$s2, $b2] = $hit('/search', ['q' => '_']);
    truthy($s2 === 200, '搜 _ 应 200，实际 ' . $s2);
    truthy(str_contains($b2, 'data-stats-state="empty"'), '下划线被当成通配符');
});

check('超长关键词：422 并说明原因，不静默截断', function () use ($hit): void {
    [$s, $b] = $hit('/search', ['q' => str_repeat('a', 81)]);
    truthy($s === 422, '超长关键词应 422，实际 ' . $s);
    truthy(str_contains($b, '关键词过长'), '没有说明是关键词过长');
    truthy(str_contains($b, '80'), '没有说明上限');
});

dropTables($prefix);
Fs::removeDir($site);
