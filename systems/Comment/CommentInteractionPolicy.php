<?php
declare(strict_types=1);
namespace Wx\Systems\Comment;

use Wx\Contracts\AccessControlInterface;

/**
 * 互动策略 —— 纯逻辑、无 IO，全部规则可表驱动穷举。
 *
 * 只回答四件事：能不能互动、能不能编辑、编辑冲突怎么判、举报到没到阈值。
 * 时间由调用方注入（$now），绝不内部读 time()：否则「窗口边界」这类断言
 * 只能靠 sleep 测，守卫会变得又慢又不确定。
 */
final class CommentInteractionPolicy
{
    /** 举报原因白名单：不在其中的值直接 422，不静默归到 other。 */
    public const REASONS = ['spam', 'abuse', 'offtopic', 'other'];

    /** 只有已发布的评论能被点赞 / 举报：给待审或垃圾评论刷赞毫无意义。 */
    private const INTERACTABLE = [CommentPolicy::PUBLISHED];

    /** 可编辑的状态：草稿态的 pending 也允许作者改，published 之后才受窗口约束。 */
    private const EDITABLE = [CommentPolicy::PENDING, CommentPolicy::PUBLISHED];

    public static function reasonValid(string $reason): bool
    {
        return in_array($reason, self::REASONS, true);
    }

    public static function interactable(array $row): bool
    {
        return in_array((string) ($row['status'] ?? ''), self::INTERACTABLE, true)
            && !CommentPolicy::isDeleted($row);
    }

    /** 达到阈值只把 published 打回 pending；其他状态不反复翻，避免审核动作被打断。 */
    public static function shouldAutoHide(array $row, int $reportCount, int $threshold): bool
    {
        if ($threshold <= 0 || $reportCount < $threshold) return false;
        return (string) ($row['status'] ?? '') === CommentPolicy::PUBLISHED;
    }

    /**
     * 编辑授权 + 窗口判定。窗口从 created_at 起算，不从 edited_at 续命：
     * 否则每改一次就延长一次，「发布后 10 分钟内可编辑」会变成「永久可编辑」。
     *
     * @return array{ok: bool, code: int, reason: string, remaining: int}
     */
    public static function editable(
        array $row, ?array $identity, ?AccessControlInterface $acl, int $now, int $windowMinutes
    ): array {
        if (CommentPolicy::isDeleted($row)) {
            return ['ok' => false, 'code' => 40914, 'reason' => 'deleted', 'remaining' => 0];
        }
        if (!in_array((string) ($row['status'] ?? ''), self::EDITABLE, true)) {
            return ['ok' => false, 'code' => 40914, 'reason' => 'status', 'remaining' => 0];
        }
        if (!CommentPolicy::ownedBy($identity, $row)) {
            // 管理员也不能改别人的评论：编辑是「作者修正自己的话」，不是审核权限。
            return ['ok' => false, 'code' => 40301, 'reason' => 'not_owner', 'remaining' => 0];
        }
        $created = strtotime((string) ($row['created_at'] ?? ''));
        if ($created === false) {
            return ['ok' => false, 'code' => 40912, 'reason' => 'no_timestamp', 'remaining' => 0];
        }
        $age = $now - $created;
        if ($age < 0) {
            // 未来时间戳（时钟漂移 / 导入数据）不能变成「永远可编辑」。
            return ['ok' => false, 'code' => 40912, 'reason' => 'future_timestamp', 'remaining' => 0];
        }
        $limit = max(0, $windowMinutes) * 60;
        if ($age > $limit) {
            return ['ok' => false, 'code' => 40912, 'reason' => 'window_closed', 'remaining' => 0];
        }
        return ['ok' => true, 'code' => 0, 'reason' => '', 'remaining' => $limit - $age];
    }
}
