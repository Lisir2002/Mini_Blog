<?php
declare(strict_types=1);
namespace Wx\Systems\Comment;

use Wx\Contracts\CommentStoreInterface;

/**
 * 内存适配器：证明「契约与实现可分离」，也是拔掉数据库时的降级路径。
 * 进程内可见、跨请求不可见；树形语义必须与 PDO 实现完全一致，否则降级会改变行为。
 */
final class MemoryCommentStore implements CommentStoreInterface
{
    /** @var array<int, array<string, mixed>> */
    private static array $rows = [];
    private static int $seq = 0;

    public function add(array $comment): int
    {
        $id = ++self::$seq;
        $row = $comment;
        $row['id']        = $id;
        $row['post_slug'] = (string) ($comment['post_slug'] ?? $comment['slug'] ?? '');
        $row['slug']      = $row['post_slug'];
        $row['status']    = (string) ($comment['status'] ?? CommentPolicy::PENDING);
        $row['parent_id'] = (int) ($comment['parent_id'] ?? CommentTree::ROOT);
        $row['depth']     = (int) ($comment['depth'] ?? 0);
        $row['author_id'] = (int) ($comment['author_id'] ?? 0);
        $row['created_at'] = (string) ($comment['created_at'] ?? date('Y-m-d H:i:s'));
        self::$rows[$id] = $row;
        return $id;
    }

    public function forPost(string $slug, int $limit = 0): array
    {
        $out = [];
        foreach (self::$rows as $row) {
            if ($row['post_slug'] === $slug) $out[] = $row;
        }
        usort($out, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
        return $limit > 0 ? array_slice($out, 0, $limit) : $out;
    }

    public function find(int $id): ?array
    {
        return self::$rows[$id] ?? null;
    }

    public function update(int $id, array $fields): bool
    {
        if (!isset(self::$rows[$id])) return false;
        foreach (['post_slug', 'parent_id', 'root_id', 'path', 'depth', 'author',
            'author_id', 'body', 'status', 'created_at', 'ip_hash', 'deleted_at'] as $column) {
            if (array_key_exists($column, $fields)) self::$rows[$id][$column] = $fields[$column];
        }
        self::$rows[$id]['slug'] = self::$rows[$id]['post_slug'];
        return true;
    }

    public function countForPost(string $slug): int
    {
        $count = 0;
        foreach (self::$rows as $row) {
            if ($row['post_slug'] === $slug && $row['status'] === CommentPolicy::PUBLISHED) $count++;
        }
        return $count;
    }

    public function counts(): array
    {
        $out = [];
        foreach (self::$rows as $row) {
            $status = (string) $row['status'];
            $out[$status] = ($out[$status] ?? 0) + 1;
        }
        ksort($out);
        return $out;
    }

    /** 仅测试用：清空静态状态，避免不同用例互相污染。 */
    public static function reset(): void
    {
        self::$rows = [];
        self::$seq = 0;
    }
}
