<?php
declare(strict_types=1);
namespace Wx\Pages\Feed;

use Wx\Contracts\ApiRegistryInterface;

/**
 * 订阅与收录的取数 —— 只读 content.posts，只取已发布。
 *
 * 订阅源和站点地图的「已发布」口径必须与归档、搜索完全一致：
 * 否则草稿会出现在 RSS 里（读者能看到未完成的内容），
 * 而定时文章又可能被爬虫提前收录。
 */
final class FeedQuery
{
    public const LIMIT = 20;
    public const SITEMAP_LIMIT = 500;

    public function __construct(private ApiRegistryInterface $registry) {}

    /** @return list<array<string, mixed>> */
    public function latest(int $limit = self::LIMIT): array
    {
        $r = $this->registry->execute('content.posts', ['status' => 'published', 'limit' => $limit]);
        return self::items($r);
    }

    /** @return list<string> 已发布文章的 slug（站点地图用） */
    public function slugs(): array
    {
        $out = [];
        $cursor = '';
        while (count($out) < self::SITEMAP_LIMIT) {
            $r = $this->registry->execute('content.posts', [
                'status' => 'published', 'limit' => 100, 'cursor' => $cursor,
            ]);
            $page = self::items($r);
            foreach ($page as $post) {
                $slug = (string) ($post['slug'] ?? '');
                if ($slug !== '' && count($out) < self::SITEMAP_LIMIT) $out[] = $slug;
            }
            $next = (string) ($r['next_cursor'] ?? '');
            if ($page === [] || $next === '') break;
            $cursor = $next;
        }
        return $out;
    }

    /** @param array<string, mixed> $raw @return list<array<string, mixed>> */
    private static function items(array $raw): array
    {
        return array_values(array_filter((array) ($raw['items'] ?? []), 'is_array'));
    }
}
