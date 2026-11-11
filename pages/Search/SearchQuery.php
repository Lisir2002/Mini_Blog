<?php
declare(strict_types=1);
namespace Wx\Pages\Search;

use Wx\Contracts\ApiRegistryInterface;

/**
 * 搜索取数 —— 复用 content.posts 的 keyword，不在页面里重写筛选口径。
 *
 * 三条不变量：
 *  1. 只搜已发布（status=published）：草稿、定时未到点、回收站里的内容不能从搜索漏出去。
 *  2. total 与 items 同口径：接口的 total 由同一套 WHERE 算出，页面只搬不造。
 *  3. 截断必须显式：本页最多 LIMIT 条，total 更大时置 truncated，
 *     让读者知道「还有更多」，而不是以为全站只有 20 篇。
 */
final class SearchQuery
{
    public const LIMIT = 20;

    public function __construct(private ApiRegistryInterface $registry) {}

    /** @return array{items: list<array<string, mixed>>, total: int, shown: int, truncated: bool} */
    public function run(string $term): array
    {
        $r = $this->registry->execute('content.posts', [
            'status'  => 'published',
            'limit'   => self::LIMIT,
            'keyword' => $term,
        ]);
        $items = array_values(array_filter((array) ($r['items'] ?? []), 'is_array'));
        $total = (int) ($r['total'] ?? 0);

        return [
            'items'     => $items,
            'total'     => $total,
            'shown'     => count($items),
            'truncated' => $total > count($items),
        ];
    }
}
