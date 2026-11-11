<?php
declare(strict_types=1);
namespace Wx\Systems\Comment;

use Wx\Contracts\CommentStoreInterface;

/**
 * 互动台账的内存实现 —— 拔掉数据库时的降级路径，语义必须与 PDO 版一致。
 *
 * 进程内可见、跨请求不可见，这一点与 MemoryCommentStore 完全对等。
 * 编辑通过主表契约 update() 生效，不另起一套「内存里的评论副本」，
 * 否则降级后「改了正文但列表还是旧的」会变成最难查的那种错。
 */
final class MemoryInteractionStore implements CommentInteractionStoreInterface
{
    /** @var array<int, array<string, true>> */
    private static array $votes = [];
    /** @var array<int, array<string, string>> */
    private static array $reports = [];

    public function __construct(private CommentStoreInterface $comments) {}

    public function vote(int $commentId, string $actorKey, bool $add): array
    {
        if ($add) {
            $changed = !isset(self::$votes[$commentId][$actorKey]);
            self::$votes[$commentId][$actorKey] = true;
        } else {
            $changed = isset(self::$votes[$commentId][$actorKey]);
            unset(self::$votes[$commentId][$actorKey]);
        }
        $likes = count(self::$votes[$commentId] ?? []);
        return ['changed' => $changed, 'likes' => $likes, 'liked' => $add];
    }

    public function report(int $commentId, string $actorKey, string $reason): array
    {
        $changed = !isset(self::$reports[$commentId][$actorKey]);
        if ($changed) self::$reports[$commentId][$actorKey] = $reason;
        return [
            'changed'  => $changed,
            'reports'  => count(self::$reports[$commentId] ?? []),
            'reported' => true,
        ];
    }

    public function countsFor(array $commentIds, string $actorKey): array
    {
        $out = [];
        foreach ($commentIds as $id) {
            $id = (int) $id;
            $out[$id] = [
                'likes'    => count(self::$votes[$id] ?? []),
                'reports'  => count(self::$reports[$id] ?? []),
                'liked'    => isset(self::$votes[$id][$actorKey]),
                'reported' => isset(self::$reports[$id][$actorKey]),
            ];
        }
        return $out;
    }

    public function reportCount(int $commentId): int
    {
        return count(self::$reports[$commentId] ?? []);
    }

    public function edit(int $commentId, string $body, int $expectedEditCount): array
    {
        $row = $this->comments->find($commentId);
        if ($row === null || CommentPolicy::isDeleted($row)) {
            return ['changed' => false, 'edit_count' => $expectedEditCount];
        }
        $current = (int) ($row['edit_count'] ?? 0);
        if ($current !== $expectedEditCount) {
            return ['changed' => false, 'edit_count' => $current];
        }
        $this->comments->update($commentId, [
            'body'       => $body,
            'edited_at'  => date('Y-m-d H:i:s'),
            'edit_count' => $current + 1,
        ]);
        return ['changed' => true, 'edit_count' => $current + 1];
    }

    public function stats(int $commentId): array
    {
        $row = $this->comments->find($commentId);
        return [
            'likes'      => count(self::$votes[$commentId] ?? []),
            'reports'    => count(self::$reports[$commentId] ?? []),
            'edit_count' => is_array($row) ? (int) ($row['edit_count'] ?? 0) : 0,
            'edited_at'  => is_array($row) && isset($row['edited_at']) ? (string) $row['edited_at'] : null,
        ];
    }

    public function queue(int $limit, int $offset): array
    {
        $rows = $this->queueRows();
        return array_map(
            static fn(array $r): array => [
                'comment_id' => (int) $r['comment_id'],
                'reports'    => (int) $r['reports'],
                'likes'      => (int) $r['likes'],
            ],
            array_slice($rows, max(0, $offset), max(1, $limit))
        );
    }

    public function queueTotal(): int { return count($this->queueRows()); }

    /** @return list<array{comment_id:int, reports:int, likes:int}> */
    private function queueRows(): array
    {
        // 口径必须与 PDO 版逐字一致：待审（pending）+ 有举报，排除已删除。
        // 只遍历「有举报的 id」会让降级后的队列少掉全部纯待审评论 —— 那正是
        // 最需要审核的一批，且不会有任何报错，属于典型的安静地错。
        $rows = $this->comments instanceof MemoryCommentStore
            ? MemoryCommentStore::allRows()
            : [];
        $ids = array_unique(array_merge(array_keys($rows), array_keys(self::$reports)));
        $out = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            $row = $rows[$id] ?? $this->comments->find($id);
            if ($row === null || CommentPolicy::isDeleted($row)) continue;
            $reports = count(self::$reports[$id] ?? []);
            $pending = (string) ($row['status'] ?? '') === CommentPolicy::PENDING;
            if (!$pending && $reports === 0) continue;
            $out[] = [
                'comment_id' => $id,
                'reports'    => $reports,
                'likes'      => count(self::$votes[$id] ?? []),
            ];
        }
        usort($out, static fn(array $a, array $b): int
            => $b['reports'] <=> $a['reports'] ?: $a['comment_id'] <=> $b['comment_id']);
        return $out;
    }

    /** 仅供守卫复位，避免静态状态跨用例累积。 */
    public static function reset(): void
    {
        self::$votes   = [];
        self::$reports = [];
    }
}
