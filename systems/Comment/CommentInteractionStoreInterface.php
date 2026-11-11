<?php
declare(strict_types=1);
namespace Wx\Systems\Comment;

/**
 * 互动台账存储契约 —— 系统层内部契约（与 MetricStoreInterface 同级，不进 core/Contracts）。
 *
 * 主评论表归 CommentStoreInterface，点赞/举报/编辑留痕归这里。
 * 分开的理由：台账是「谁对谁做过什么」的去重集合，语义与主表完全不同，
 * 塞进主表会让 forPost() 这种读路径被迫 JOIN 出 O(N²) 的行。
 *
 * 所有写方法都必须幂等：同一主体对同一评论重复操作返回 changed=false，
 * 计数不变。并发安全由联合主键保证，不靠「先查再插」。
 */
interface CommentInteractionStoreInterface
{
    /** @return array{changed: bool, likes: int, liked: bool} */
    public function vote(int $commentId, string $actorKey, bool $add): array;

    /** @return array{changed: bool, reports: int, reported: bool} */
    public function report(int $commentId, string $actorKey, string $reason): array;

    /**
     * 批量取计数 —— 列表页唯一允许的查询方式。
     * @param list<int> $commentIds
     * @return array<int, array{likes: int, reports: int, liked: bool, reported: bool}>
     */
    public function countsFor(array $commentIds, string $actorKey): array;

    /** 某评论的独立举报数（阈值判定用，不把同一人的重复举报算进去）。 */
    public function reportCount(int $commentId): int;

    /**
     * 编辑：比较并设置。$expectedEditCount 不匹配返回 changed=false，
     * 由调用方译成 40913 —— 不能靠 rowCount() 判断，值没变时 MySQL 也返回 0。
     * @return array{changed: bool, edit_count: int}
     */
    public function edit(int $commentId, string $body, int $expectedEditCount): array;

    /** @return array{likes: int, reports: int, edit_count: int, edited_at: ?string} */
    public function stats(int $commentId): array;

    /**
     * 审核队列：按举报数降序、创建时间升序取待审 + 被举报的评论 ID。
     * @return list<array{comment_id: int, reports: int, likes: int}>
     */
    public function queue(int $limit, int $offset): array;

    public function queueTotal(): int;
}
