<?php
declare(strict_types=1);
namespace Wx\Systems\Comment;

use Wx\Contracts\AccessControlInterface;

/**
 * 评论策略 —— 可见性、删除授权、审核状态机。三件事都只依赖身份与事实，不依赖主题。
 *
 * 状态机是显式白名单而非「if 判断散布各处」：非法迁移一律拒绝（40901），
 * 这样「已删除的评论被审核回来」「垃圾评论被直接发布」这类脏数据在入口就断了。
 */
final class CommentPolicy
{
    public const PENDING   = 'pending';
    public const PUBLISHED = 'published';
    public const HIDDEN    = 'hidden';
    public const SPAM      = 'spam';
    public const DELETED   = 'deleted';

    /** 审核迁移白名单：目标状态 => 允许的来源状态。deleted 是终态，任何来源都进不去也出不来。 */
    private const TRANSITIONS = [
        self::PUBLISHED => [self::PENDING, self::HIDDEN, self::SPAM],
        self::HIDDEN    => [self::PENDING, self::PUBLISHED, self::SPAM],
        self::SPAM      => [self::PENDING, self::PUBLISHED, self::HIDDEN],
        self::PENDING   => [self::PUBLISHED, self::HIDDEN, self::SPAM],
    ];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($from, self::TRANSITIONS[$to] ?? [], true);
    }

    /** 公开可见：只有 published；deleted 保留占位由主题显示「已删除」，不算内容可见。 */
    public static function isPublic(array $comment): bool
    {
        return (string) ($comment['status'] ?? '') === self::PUBLISHED;
    }

    public static function isDeleted(array $comment): bool
    {
        return (string) ($comment['status'] ?? '') === self::DELETED;
    }

    /**
     * 当前身份能不能看到这条评论。
     * 已删除永远保留占位（保住回复链），但正文在存储层已清空，因此对谁都算「可见的空壳」。
     */
    public static function visible(?array $identity, array $comment, ?AccessControlInterface $acl): bool
    {
        if (self::isPublic($comment) || self::isDeleted($comment)) return true;
        if ($identity === null) return false;
        if (self::ownedBy($identity, $comment)) return true;
        return $acl !== null && $acl->allows($identity, 'comment.moderate');
    }

    /** 删除授权：先问 ACL「能否删任意」，再问「能否删自己的」；匿名评论没有主人，只能走前者。 */
    public static function canDelete(?array $identity, array $comment, ?AccessControlInterface $acl): bool
    {
        if ($identity === null || $acl === null) return false;
        $resource = ['type' => 'comment', 'id' => (string) ($comment['id'] ?? ''),
            'author_id' => (int) ($comment['author_id'] ?? 0), 'status' => (string) ($comment['status'] ?? '')];
        if ($acl->allows($identity, 'comment.delete.any', $resource)) return true;
        return self::ownedBy($identity, $comment) && $acl->allows($identity, 'comment.delete.own', $resource);
    }

    /** 审核授权：单独一个权限点，避免「能删自己的」被误当成「能审全站」。 */
    public static function canModerate(?array $identity, ?AccessControlInterface $acl): bool
    {
        return $identity !== null && $acl !== null && $acl->allows($identity, 'comment.moderate');
    }

    public static function ownedBy(?array $identity, array $comment): bool
    {
        $owner = (int) ($comment['author_id'] ?? 0);
        return $identity !== null && $owner > 0 && (int) ($identity['user_id'] ?? 0) === $owner;
    }
}
