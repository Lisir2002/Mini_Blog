<?php
declare(strict_types=1);
namespace Wx\Pages\Feed;

use Wx\Systems\Data\Utf8Text;

/**
 * 协议文档序列化 —— RSS 2.0 / sitemap / robots 的纯函数。
 *
 * 为什么序列化放在页面而不是主题：FAQ 里最容易走偏的一条是「让主题渲染 XML」。
 * 主题的职责是站点外观，订阅源不是外观 —— 它必须逐字符合 RSS 规范，
 * 一旦允许主题改结构，阅读器就会解析失败。因此三个文档由页面直接产出，
 * 响应类型在这里显式声明，主题不参与（layout=raw）。
 *
 * 所有文本都按 XML 转义；URL 一律绝对地址（相对地址在阅读器里无法解析）。
 */
final class FeedSerialize
{
    /**
     * @param list<array<string, mixed>> $posts
     */
    public static function rss(array $posts, string $base, string $siteName): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<rss version="2.0"><channel>' . "\n"
            . '  <title>' . self::x($siteName) . '</title>' . "\n"
            . '  <link>' . self::x($base . '/') . '</link>' . "\n"
            . '  <description>' . self::x($siteName . ' 的最新文章') . '</description>' . "\n"
            . '  <language>zh-CN</language>' . "\n";

        foreach ($posts as $post) {
            $slug = (string) ($post['slug'] ?? '');
            if ($slug === '') continue;
            $link = $base . '/post?slug=' . rawurlencode($slug);
            $body = (string) ($post['excerpt'] ?? '');
            if (trim($body) === '') $body = (string) ($post['body'] ?? '');
            $xml .= '  <item>' . "\n"
                . '    <title>' . self::x((string) ($post['title'] ?? '(无标题)')) . '</title>' . "\n"
                . '    <link>' . self::x($link) . '</link>' . "\n"
                . '    <guid isPermaLink="true">' . self::x($link) . '</guid>' . "\n"
                . '    <pubDate>' . self::date((string) ($post['created_at'] ?? '')) . '</pubDate>' . "\n"
                . '    <description>' . self::x(self::excerpt($body)) . '</description>' . "\n"
                . '  </item>' . "\n";
        }
        return $xml . '</channel></rss>' . "\n";
    }

    /**
     * 站点地图：静态页 + 文章（带 lastmod）+ 有文章挂载的标签页。
     *
     * URL 总数上限 $limit：超出时用 XML 注释显式声明截断 —— 站点地图协议没有
     * 「未列全」字段，沉默截断会让爬虫把 500 条当成全部收录目标。
     *
     * @param list<array{slug: string, lastmod: string}> $posts
     * @param array{state: string, items: list<array{kind: string, slug: string}>} $terms
     */
    public static function sitemap(array $posts, array $terms, string $base, int $limit = FeedQuery::SITEMAP_LIMIT): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        $budget = $limit;
        foreach (['/' => '1.0', '/archive' => '0.6', '/terms' => '0.5', '/links' => '0.4'] as $path => $priority) {
            $xml .= self::url($base . $path, $priority);
            $budget--;
        }
        $truncatedPosts = false;
        foreach ($posts as $post) {
            if ($budget <= 0) { $truncatedPosts = true; break; }
            $xml .= self::url($base . '/post?slug=' . rawurlencode((string) $post['slug']), '0.8', self::w3c((string) ($post['lastmod'] ?? '')));
            $budget--;
        }
        $truncatedTerms = false;
        foreach ((array) ($terms['items'] ?? []) as $term) {
            if ($budget <= 0) { $truncatedTerms = true; break; }
            $href = '/term?kind=' . rawurlencode((string) $term['kind'])
                . '&slug=' . rawurlencode((string) $term['slug']);
            $xml .= self::url($base . $href, '0.4');
            $budget--;
        }
        if (($terms['state'] ?? '') === 'unavailable') {
            $xml .= "<!-- terms segment unavailable: term listing API not reachable; posts are still listed -->\n";
        }
        if ($truncatedPosts || $truncatedTerms) {
            $xml .= "<!-- truncated at {$limit} URLs: not every eligible URL is listed -->\n";
        }
        return $xml . '</urlset>' . "\n";
    }

    public static function robots(string $base): string
    {
        return "User-agent: *\nAllow: /\nDisallow: /console\nDisallow: /account\nDisallow: /install\n\n"
            . 'Sitemap: ' . $base . "/sitemap.xml\n";
    }

    private static function url(string $location, string $priority, string $lastmod = ''): string
    {
        $line = '  <url><loc>' . self::x($location) . '</loc><priority>' . $priority . '</priority>';
        if ($lastmod !== '') $line .= '<lastmod>' . self::x($lastmod) . '</lastmod>';
        return $line . "</url>\n";
    }

    /** W3C Datetime（sitemap 协议只认完整日期或更细）；脏日期返回空串即省略字段。 */
    private static function w3c(string $value): string
    {
        $ts = strtotime($value);
        return $ts === false ? '' : gmdate('Y-m-d', $ts);
    }

    private static function date(string $value): string
    {
        $ts = strtotime($value);
        return gmdate('D, d M Y H:i:s', $ts === false ? 0 : $ts) . ' +0000';
    }

    private static function excerpt(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));
        return Utf8Text::clip($text, 200);
    }

    private static function x(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
