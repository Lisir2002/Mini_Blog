<?php
declare(strict_types=1);
namespace Wx\Pages\Queue;

use Wx\Contracts\ApiRegistryInterface;

/**
 * 审核队列取数 —— 只调一个既有接口，不做权限判断、不拼标签。
 *
 * 权限由 page.json 的 access 段在 route.access 阶段裁决（auth=admin +
 * comment.moderate），页面执行时已经过闸；这里再判一次只会让两处口径有机会分叉。
 * 取数只负责把「取到多少 / 是否被截断 / 是否真的为空」三件事分开表达。
 */
final class QueueQuery
{
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT = 100;

    public function __construct(private ApiRegistryInterface $registry) {}

    /**
     * @return array{items: list<array<string, mixed>>, total: int, shown: int,
     *               truncated: bool, empty: bool, limit: int, offset: int}
     */
    public function fetch(int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $limit  = max(1, min(self::MAX_LIMIT, $limit));
        $offset = max(0, $offset);

        $raw = (array) $this->registry->execute('comments.queue', [
            'limit' => $limit, 'offset' => $offset,
        ]);

        $items = [];
        foreach ((array) ($raw['items'] ?? []) as $row) {
            if (!is_array($row)) continue;
            $items[] = [
                'id'         => (int) ($row['id'] ?? 0),
                'post_slug'  => (string) ($row['post_slug'] ?? ''),
                'author'     => (string) ($row['author'] ?? ''),
                'body'       => (string) ($row['body'] ?? ''),
                'status'     => (string) ($row['status'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'likes'      => (int) ($row['likes'] ?? 0),
                'reports'    => (int) ($row['reports'] ?? 0),
            ];
        }

        $total = (int) ($raw['total'] ?? count($items));
        return [
            'items'     => $items,
            'total'     => $total,
            'shown'     => count($items),
            'truncated' => (bool) ($raw['truncated'] ?? false) || $offset + count($items) < $total,
            'empty'     => $total === 0,
            'limit'     => $limit,
            'offset'    => $offset,
        ];
    }
}
