<?php
declare(strict_types=1);
namespace Wx\Systems\Comment;

use Wx\Contracts\CommentStoreInterface;

/**
 * v0.1 内存适配器，用来证明「契约与实现可分离」。
 * 换成 MySQL 只需：新写一个 MysqlCommentStore implements CommentStoreInterface，
 * 然后在 CommentSystem 里把绑定指向它 —— 主题、插件、核心全部无需改动。
 */
final class MemoryCommentStore implements CommentStoreInterface
{
    /** @var list<array<string, mixed>> */
    private static array $rows = [];

    public function add(array $comment): int
    {
        self::$rows[] = $comment;
        return count(self::$rows);
    }

    public function forPost(string $slug): array
    {
        return array_values(array_filter(self::$rows, fn(array $row): bool => ($row['slug'] ?? null) === $slug));
    }
}
