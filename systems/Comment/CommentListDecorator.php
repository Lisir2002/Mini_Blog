<?php
declare(strict_types=1);
namespace Wx\Systems\Comment;

/**
 * 列表装饰协作者 —— 把「点赞 / 举报计数」一次性批量补进评论列表。
 *
 * 独立成类的原因不是美观：comments.list 的 read_limit 默认 2000，
 * 若逐条查台账就是 2000 次往返，热文页会直接拖垮数据库。
 * 这里固定三条 SQL（votes 聚合 / reports 聚合 / 当前主体的两条存在性），
 * 与条目数 N 无关 —— 这是可测的效率不变量，不是一句注释。
 */
final class CommentListDecorator
{
    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function apply(array $items, string $actorKey, CommentInteractionStoreInterface $store): array
    {
        if ($items === []) return $items;
        $ids = array_map(static fn(array $r): int => (int) ($r['id'] ?? 0), $items);
        $counts = $store->countsFor($ids, $actorKey);
        $out = [];
        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? 0);
            $c  = $counts[$id] ?? ['likes' => 0, 'reports' => 0, 'liked' => false, 'reported' => false];
            $item['likes']    = $c['likes'];
            $item['reports']  = $c['reports'];
            $item['liked']    = $c['liked'];
            $item['reported'] = $c['reported'];
            $out[] = $item;
        }
        return $out;
    }
}
