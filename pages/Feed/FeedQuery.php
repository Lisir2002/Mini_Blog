<?php
declare(strict_types=1);
namespace Wx\Pages\Feed;

use Wx\Contracts\ApiRegistryInterface;

/**
 * 订阅与收录的取数 —— 只读 content.posts / content.terms，只取已发布。
 *
 * 订阅源和站点地图的「已发布」口径必须与归档、搜索完全一致：
 * 否则草稿会出现在 RSS 里（读者能看到未完成的内容），
 * 而定时文章又可能被爬虫提前收录。
 *
 * 站点地图与 RSS 的取数边界不同：RSS 失败就该 503（阅读器拿空 feed 会缓存
 * 「这个站没有文章」）；sitemap 里的标签段取不到（如内存降级 50101）只影响
 * 标签页收录 —— 文章收录不该陪着下线，因此 terms() 显式回报状态而不是抛异常。
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

    /**
     * 已发布文章条目（站点地图用）：slug + lastmod（发布时间，契约只保证 created_at）。
     *
     * @return list<array{slug: string, lastmod: string}>
     */
    public function entries(): array
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
                if ($slug === '' || count($out) >= self::SITEMAP_LIMIT) continue;
                $out[] = ['slug' => $slug, 'lastmod' => (string) ($post['created_at'] ?? '')];
            }
            $next = (string) ($r['next_cursor'] ?? '');
            if ($page === [] || $next === '') break;
            $cursor = $next;
        }
        return $out;
    }

    /**
     * 有文章挂载的术语（站点地图用）：post_count=0 的标签页是空列表，不该被收录。
     * 接口不可用（内存降级 50101）时显式回报 state，由序列化层留痕，不抛异常。
     *
     * @return array{state: string, items: list<array{kind: string, slug: string}>}
     */
    public function terms(): array
    {
        try {
            $r = $this->registry->execute('content.terms', ['kind' => 'any']);
        } catch (\Throwable) {
            return ['state' => 'unavailable', 'items' => []];
        }
        $out = [];
        foreach (self::items($r) as $term) {
            $slug = (string) ($term['slug'] ?? '');
            $kind = (string) ($term['kind'] ?? '');
            if ($slug === '' || $kind === '' || (int) ($term['post_count'] ?? 0) <= 0) continue;
            if (count($out) >= self::SITEMAP_LIMIT) break;
            $out[] = ['kind' => $kind, 'slug' => $slug];
        }
        return ['state' => 'ok', 'items' => $out];
    }

    /** @param array<string, mixed> $raw @return list<array<string, mixed>> */
    private static function items(array $raw): array
    {
        return array_values(array_filter((array) ($raw['items'] ?? []), 'is_array'));
    }
}
