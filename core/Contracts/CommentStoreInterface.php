<?php
declare(strict_types=1);
namespace Wx\Contracts;

interface CommentStoreInterface
{
    /** @param array<string, mixed> $comment @return int 评论 ID */
    public function add(array $comment): int;

    /** @return list<array<string, mixed>> */
    public function forPost(string $slug): array;
}
