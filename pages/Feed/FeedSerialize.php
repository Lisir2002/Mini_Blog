<?php
declare(strict_types=1);
namespace Wx\Pages\Feed;

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

    /** @param list<string> $slugs */
    public static function sitemap(array $slugs, string $base): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach (['/' => '1.0', '/archive' => '0.6', '/terms' => '0.5'] as $path => $priority) {
            $xml .= self::url($base . $path, $priority);
        }
        foreach ($slugs as $slug) {
            $xml .= self::url($base . '/post?slug=' . rawurlencode($slug), '0.8');
        }
        return $xml . '</urlset>' . "\n";
    }

    public static function robots(string $base): string
    {
        return "User-agent: *\nAllow: /\nDisallow: /console\nDisallow: /install\n\n"
            . 'Sitemap: ' . $base . "/sitemap.xml\n";
    }

    private static function url(string $location, string $priority): string
    {
        return '  <url><loc>' . self::x($location) . '</loc><priority>' . $priority . "</priority></url>\n";
    }

    private static function date(string $value): string
    {
        $ts = strtotime($value);
        return gmdate('D, d M Y H:i:s', $ts === false ? 0 : $ts) . ' +0000';
    }

    private static function excerpt(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));
        return function_exists('mb_substr') ? mb_substr($text, 0, 200) : substr($text, 0, 200);
    }

    private static function x(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
