<?php
declare(strict_types=1);
namespace Wx\Contracts;

/**
 * 评论存储契约 —— 只描述「评论怎么存、怎么取」，不描述「谁能评论、怎么审核」。
 *
 * 路径与深度由评论系统在写入前算好，存储层不推导业务规则：
 * 换一个实现（MySQL / SQLite / 外部服务）时，树形语义必须完全一致。
 */
interface CommentStoreInterface
{
    /** @param array<string, mixed> $comment @return int 评论 ID */
    public function add(array $comment): int;

    /** 一篇文章的全部评论，按 id 升序；含待审核与已删除占位，由上层决定怎么展示
     *  @return list<array<string, mixed>> */
    public function forPost(string $slug): array;

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array;

    /** @param array<string, mixed> $fields 只接受白名单列 @return bool 是否命中一行 */
    public function update(int $id, array $fields): bool;

    /** 公开条数：不含已删除与垃圾评论 */
    public function countForPost(string $slug): int;
}
