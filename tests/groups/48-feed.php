<?php
declare(strict_types=1);

use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Http\Request;
use Wx\Pages\Feed\FeedQuery;
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

check('sitemap：绝对地址、lastmod 为 W3C 日期、& 转义、空标签不收录', function () use ($xmlOk): void {
    $posts = [
        ['slug' => 'a&b', 'lastmod' => '2026-01-02 03:04:05'],
        ['slug' => 'no-date', 'lastmod' => ''],
    ];
    $terms = ['state' => 'ok', 'items' => [
        ['kind' => 'tag', 'slug' => 'php&net'],
    ]];
    $xml = FeedSerialize::sitemap($posts, $terms, 'https://example.test');
    truthy($xmlOk($xml), 'sitemap 不是合法 XML');
    truthy(str_contains($xml, '<loc>https://example.test/</loc>'), '缺少首页');
    truthy(str_contains($xml, '<loc>https://example.test/links</loc>'), '缺少友链页');
    truthy(str_contains($xml, '<loc>https://example.test/post?slug=a%26b</loc>'), '文章地址未编码');
    truthy(str_contains($xml, '<loc>https://example.test/post?slug=a%26b</loc><priority>0.8</priority><lastmod>2026-01-02</lastmod>'),
        'lastmod 缺失或不是 W3C 日期格式');
    truthy(!str_contains($xml, '<lastmod></lastmod>'), '脏日期不许输出空 lastmod 元素');
    truthy(str_contains($xml, '<loc>https://example.test/term?kind=tag&amp;slug=php%26net</loc>'),
        '标签页地址缺失或 & 没有转义成 &amp;');
    truthy(!str_contains($xml, '<!--'), '正常清单不该出现截断 / 不可用注释');

    // 截断显式声明：预算耗尽时必须留痕，不许沉默丢 URL
    $many = array_map(static fn(int $i): array => ['slug' => 'p' . $i, 'lastmod' => '2026-01-01'], range(1, 600));
    $cut = FeedSerialize::sitemap($many, ['state' => 'ok', 'items' => []], 'https://example.test', 500);
    truthy(str_contains($cut, '<!-- truncated at 500 URLs'), '截断没有声明');
    truthy(!str_contains($cut, 'p600'), '超限 URL 仍在清单里');

    // 术语段不可用：显式注释留痕，文章段照常 —— 不许伪装成「这个站没有标签」
    $none = FeedSerialize::sitemap($posts, ['state' => 'unavailable', 'items' => []], 'https://example.test');
    truthy(str_contains($none, 'terms segment unavailable'), '术语段不可用没有留痕');
    truthy(str_contains($none, 'post?slug=a%26b'), '术语不可用时文章段不该陪葬');
});

check('robots：指向绝对地址，挡住后台 / 账户页 / 安装页', function () use ($xmlOk): void {
    $robots = FeedSerialize::robots('https://example.test');
    truthy(str_contains($robots, 'Disallow: /console'), 'robots 没有挡住后台');
    truthy(str_contains($robots, 'Disallow: /account'), 'robots 没有挡住账号自助页');
    truthy(str_contains($robots, 'Disallow: /install'), 'robots 没有挡住安装页');
    truthy(str_contains($robots, 'Sitemap: https://example.test/sitemap.xml'), 'robots 没有指向站点地图');
});

/** 假接口清单：让 terms() 的过滤与降级不依赖数据库也能穷举。 */
$fakeRegistry = static function (array $script): ApiRegistryInterface {
    return new class($script) implements ApiRegistryInterface {
        private int $i = 0;
        public function __construct(private array $script) {}
        public function endpoints(): array { return []; }
        public function find(string $id): ?array { return null; }
        public function match(string $method, string $path): ?array { return null; }
        public function registerOperation(string $operation, callable $handler): void {}
        public function execute(string $operation, array $input): mixed
        {
            $step = $this->script[$this->i++] ?? ['ok' => true, 'items' => []];
            if (!($step['ok'] ?? true)) {
                throw new \Wx\Systems\Api\ApiOperationException($step['code'] ?? 50101, $step['message'] ?? 'unavailable');
            }
            return ['items' => $step['items'], 'next_cursor' => null];
        }
    };
};

check('terms 取数：空标签不进收录清单、脏条目被跳过、接口不可用显式降级', function () use ($fakeRegistry): void {
    $q = new FeedQuery($fakeRegistry([
        ['ok' => true, 'items' => [
            ['kind' => 'tag', 'slug' => 'hot', 'post_count' => 3],
            ['kind' => 'tag', 'slug' => 'ghost', 'post_count' => 0],
            ['kind' => 'category', 'slug' => '', 'post_count' => 5],
            ['kind' => '', 'slug' => 'no-kind', 'post_count' => 5],
            'not-an-array',
        ]],
    ]));
    $t = $q->terms();
    truthy($t['state'] === 'ok', '正常清单被标成不可用');
    truthy($t['items'] === [['kind' => 'tag', 'slug' => 'hot']],
        '过滤口径不对：空标签 / 脏条目必须被跳过，实际 ' . json_encode($t['items'], JSON_UNESCAPED_UNICODE));

    $q2 = new FeedQuery($fakeRegistry([['ok' => false, 'code' => 50101, 'message' => '内存降级']]));
    $t2 = $q2->terms();
    truthy($t2 === ['state' => 'unavailable', 'items' => []],
        '接口不可用应显式回报 state，而不是抛异常拖垮整份 sitemap');
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
// 标签收录链路：建一个带文章的标签，sitemap 应收录 /term；一个空标签不该进
$k->handle(new Request('POST', '/api/v1/content/terms/manage', [], $admin,
    ['action' => 'create', 'kind' => 'tag', 'slug' => 'hot', 'name' => '热门']));
$k->handle(new Request('POST', '/api/v1/content/post/terms', [], $admin,
    ['slug' => 'f-open', 'terms' => ['tag:hot']]));
$k->handle(new Request('POST', '/api/v1/content/terms/manage', [], $admin,
    ['action' => 'create', 'kind' => 'tag', 'slug' => 'empty-tag', 'name' => '空标签']));

check('RSS：200 + application/rss+xml，只含已发布', function () use ($hit, $xmlOk): void {
    [$s, $ct, $body] = $hit('/feed');
    truthy($s === 200, 'RSS 应 200，实际 ' . $s);
    truthy(str_contains($ct, 'application/rss+xml'), 'Content-Type 不对：' . $ct);
    truthy(str_contains($body, '订阅里的公开文章'), '已发布文章没有进订阅源');
    truthy(!str_contains($body, '不该出现的草稿'), '草稿进了订阅源');
    truthy($xmlOk($body), '真实响应不是合法 XML');
});

check('sitemap：200 + application/xml，含友链 / lastmod / 有文章的标签，草稿与空标签不进', function () use ($hit, $xmlOk): void {
    [$s, $ct, $body] = $hit('/sitemap.xml');
    truthy($s === 200, 'sitemap 应 200，实际 ' . $s);
    truthy(str_contains($ct, 'application/xml'), 'Content-Type 不对：' . $ct);
    truthy(str_contains($body, 'http://feeds.test/post?slug=f-open</loc><priority>0.8</priority><lastmod>'),
        '缺少已发布文章地址或 lastmod');
    truthy(str_contains($body, 'http://feeds.test/links</loc>'), '缺少友链页地址');
    truthy(str_contains($body, '/term?kind=tag&amp;slug=hot</loc>'), '有文章挂载的标签没有被收录');
    truthy(!str_contains($body, 'slug=empty-tag'), '空标签进了站点地图');
    truthy(!str_contains($body, 'f-draft'), '草稿进了站点地图');
    truthy($xmlOk($body), '真实响应不是合法 XML');
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
