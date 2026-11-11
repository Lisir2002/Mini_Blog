<?php
declare(strict_types=1);
namespace Wx\Pages\ContentAdmin;

use Wx\Contracts\ApiRegistryInterface;

/**
 * 内容管理取数 —— 活文章与回收站两种视图，各自走既有接口。
 *
 * 两个视图不合并成一个查询：回收站里的文章状态原样保留（草稿仍是草稿），
 * 混进普通列表后「删掉的草稿」会被当成「草稿」计数，站长会以为它还在。
 *
 * 截断规则与搜索一致：本页最多 LIMIT 条，total 更大时置 truncated 并写明。
 */
final class ContentAdminQuery
{
    public const LIMIT = 20;

    public function __construct(private ApiRegistryInterface $registry) {}

    /** @return array{items: list<array<string, mixed>>, total: int, shown: int, truncated: bool} */
    public function live(string $keyword = ''): array
    {
        return $this->shape($this->registry->execute('content.posts', [
            'status' => 'any', 'limit' => self::LIMIT, 'keyword' => $keyword,
        ]));
    }

    /** @return array{items: list<array<string, mixed>>, total: int, shown: int, truncated: bool} */
    public function trash(): array
    {
        return $this->shape($this->registry->execute('content.trash', ['limit' => self::LIMIT]));
    }

    /** @param array<string, mixed> $raw */
    private function shape(array $raw): array
    {
        $items = array_values(array_filter((array) ($raw['items'] ?? []), 'is_array'));
        $total = (int) ($raw['total'] ?? count($items));
        return ['items' => $items, 'total' => $total, 'shown' => count($items),
            'truncated' => $total > count($items)];
    }
}
