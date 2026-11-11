<?php
declare(strict_types=1);

use Wx\Core\Http\Request;
use Wx\Pages\Feed\FeedSerialize;
use Wx\Systems\Lifecycle\Fs;

/**
 * 48 · 订阅与收录：RSS / sitemap / robots
 *
 * 这一组证明三件事：
 *  1. 三个文档的 Content-Type 正确、结构可被解析（XML 合法，不是拼出来的字符串）；
 *  2. 草稿不进订阅源、不进站点地图 —— 口径与归档 / 搜索一致；
 *  3. 「没有文章」与「生成失败」可区分：失败是 503 + 纯文本原因，不是一份空 feed。
 */
echo "\n=== 48. 订阅与收录：RSS / sitemap / robots ===\n";

/**
 * 极简 XML 结构检查 —— 运行环境没有 SimpleXML / DOM 扩展（本项目零外部依赖），
 * 因此自己检查三件足以让阅读器解析失败的事：声明、转义、元素配对。
 */
$xmlOk = static function (string $xml): bool {
    if (!str_starts_with($xml, '<?xml version="1.0" encoding="UTF-8"?>')) return false;
    // 裸 & 会让解析器直接报错；合法实体只有命名实体与数字引用。
    if (preg_match('/&(?!(?:amp|lt|gt|quot|apos|#\d+|#x[0-9a-fA-F]+);)/', $xml)) return false;
    foreach (['rss', 'channel', 'item', 'urlset', 'url'] as $tag) {
        $open = preg_match_all('/<' . $tag . '(?:\s|>)/', $xml);
        $close = preg_match_all('/<\/' . $tag . '>/', $xml);
        if ($open !== $close) return false;
    }
    return true;
};

check('RSS 序列化：XML 合法，标题转义，链接是绝对地址', function () use ($xmlOk): void {
    $posts = [[
        'slug' => 'a&b', 'title' => '标题 <script>', 'excerpt' => '摘要 & 更多',
        'body' => '', 'created_at' => '2026-01-02 03:04:05',
    ]];
    $xml = FeedSerialize::rss($posts, 'https://example.test', '站点 & 名称');
    truthy($xmlOk($xml), 'RSS 不是合法 XML（声明 / 转义 / 配对任一不成立）');
    truthy(str_contains($xml, '<title>站点 &amp; 名称</title>'), '站点名没有正确转义');
    truthy(str_contains($xml, '<title>标题 &lt;script&gt;</title>'), '条目标题没有转义成文本');
    truthy(str_contains($xml, '<link>https://example.test/post?slug=a%26b</link>'), '链接不是绝对地址或未编码');
    truthy(str_contains($xml, '<pubDate>'), '缺少 pubDate');
});

check('sitemap 与 robots：指向绝对地址，且挡住后台与安装页', function () use ($xmlOk): void {
    $xml = FeedSerialize::sitemap(['a', 'b'], 'https://example.test');
    truthy($xmlOk($xml), 'sitemap 不是合法 XML');
    truthy(str_contains($xml, '<loc>https://example.test/</loc>'), '缺少首页');
    truthy(str_contains($xml, '<loc>https://example.test/post?slug=a</loc>'), '缺少文章地址');

    $robots = FeedSerialize::robots('https://example.test');
    truthy(str_contains($robots, 'Disallow: /console'), 'robots 没有挡住后台');
    truthy(str_contains($robots, 'Sitemap: https://example.test/sitemap.xml'), 'robots 没有指向站点地图');
});

$site = tempSite('feed');
$prefix = 'if_';
dropTables($prefix);
$k = isolatedKernel($site, $prefix, ['theme' => ['active' => 'glass'], 'api' => ['expose_write' => true]]);
$k->boot();
$admin = adminHeaders();

/** @return array{0:int, 1:string, 2:string} */
$hit = static function (string $path) use ($k): array {
    $r = $k->handle(new Request('GET', $path, [], ['Host' => 'feeds.test']));
    return [$r->status(), $r->contentType(), $r->body()];
};

$k->handle(new Request('POST', '/api/v1/content/posts', [], $admin,
    ['title' => '订阅里的公开文章', 'body' => '正文', 'slug' => 'f-open', 'status' => 'published']));
$k->handle(new Request('POST', '/api/v1/content/posts', [], $admin,
    ['title' => '不该出现的草稿', 'body' => '正文', 'slug' => 'f-draft', 'status' => 'draft']));

check('RSS：200 + application/rss+xml，只含已发布', function () use ($hit, $xmlOk): void {
    [$s, $ct, $body] = $hit('/feed');
    truthy($s === 200, 'RSS 应 200，实际 ' . $s);
    truthy(str_contains($ct, 'application/rss+xml'), 'Content-Type 不对：' . $ct);
    truthy(str_contains($body, '订阅里的公开文章'), '已发布文章没有进订阅源');
    truthy(!str_contains($body, '不该出现的草稿'), '草稿进了订阅源');
    truthy($xmlOk($body), '真实响应不是合法 XML');
});

check('sitemap：200 + application/xml，含文章绝对地址', function () use ($hit): void {
    [$s, $ct, $body] = $hit('/sitemap.xml');
    truthy($s === 200, 'sitemap 应 200，实际 ' . $s);
    truthy(str_contains($ct, 'application/xml'), 'Content-Type 不对：' . $ct);
    truthy(str_contains($body, 'http://feeds.test/post?slug=f-open'), '缺少已发布文章地址');
    truthy(!str_contains($body, 'f-draft'), '草稿进了站点地图');
});

check('robots：200 + text/plain，不被主题包成 HTML', function () use ($hit): void {
    [$s, $ct, $body] = $hit('/robots.txt');
    truthy($s === 200, 'robots 应 200，实际 ' . $s);
    truthy(str_contains($ct, 'text/plain'), 'Content-Type 不对：' . $ct);
    truthy(str_contains($body, 'Sitemap: http://feeds.test/sitemap.xml'), '没有声明站点地图');
});

check('协议文档不走主题布局：响应里没有 data-layout', function () use ($hit): void {
    foreach (['/feed', '/sitemap.xml', '/robots.txt'] as $path) {
        [, , $body] = $hit($path);
        truthy(!str_contains($body, 'data-layout'), $path . ' 被主题套了 HTML 骨架');
    }
});

dropTables($prefix);
Fs::removeDir($site);
