<?php
declare(strict_types=1);
namespace Wx\Systems\Content;

use InvalidArgumentException;

/**
 * 文章状态机 —— 内容系统里唯一一处「什么状态能变成什么状态」的知识。
 *
 * 在 v0.28 之前，`status` 是自由字符串：`content.create` 原样写库，`content.update`
 * 只对照一个四元素白名单，多一个 `status=archive`（少个 d）就写进了库。随后所有
 * 公开列表按 `published` 过滤，那篇文章就「消失」了 —— 创建返回成功，却再也读不到。
 * 这类失败不会报错，只会让站长怀疑自己没保存。
 *
 * 所以状态必须可枚举、迁移必须可穷举：
 *   - 未知状态值 → 参数错误（42201），是拼写问题，客户端改一下就能成功；
 *   - 已知状态但当前不可达 → 状态冲突（40908），是业务顺序问题，重试无用。
 * 两者混成一个码，前端就只能显示「保存失败」。
 */
final class ContentStatus
{
    public const DRAFT     = 'draft';
    public const REVIEW    = 'review';
    public const PUBLISHED = 'published';
    public const ARCHIVED  = 'archived';

    /** 全部合法状态。新增状态必须同时补进这张表与下面的迁移矩阵。 */
    public const ALL = [self::DRAFT, self::REVIEW, self::PUBLISHED, self::ARCHIVED];

    /**
     * 允许的迁移。同状态迁移不在此表内，由 canTransition() 单独视为幂等空操作。
     * 刻意不提供 published → review：已发布内容退回审核没有业务含义，
     * 真要重审应先下架（→ draft），动作与意图一致。
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        self::DRAFT     => [self::REVIEW, self::PUBLISHED, self::ARCHIVED],
        self::REVIEW    => [self::DRAFT, self::PUBLISHED, self::ARCHIVED],
        self::PUBLISHED => [self::DRAFT, self::ARCHIVED],
        self::ARCHIVED  => [self::DRAFT, self::PUBLISHED],
    ];

    public static function isKnown(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    /** 未知状态直接抛参数错误 —— 调用方不必每题都先自己校验一遍。 */
    public static function assertKnown(string $status): void
    {
        if (!self::isKnown($status)) {
            throw new InvalidArgumentException('未知状态：' . $status);
        }
    }

    /**
     * 是否允许迁移。同状态返回 true（幂等：重复提交「发布」不算冲突），
     * 未知的当前状态返回 false —— 历史上写坏的值不能被当成任意跳板。
     */
    public static function canTransition(string $from, string $to): bool
    {
        self::assertKnown($to);
        if ($from === $to) return true;
        return in_array($to, self::ALLOWED[$from] ?? [], true);
    }

    /** @return list<string> 从当前状态可达的状态，供接口把「能做什么」直接告诉调用方 */
    public static function nextOf(string $from): array
    {
        return self::ALLOWED[$from] ?? [];
    }

    /** @return array<string, string> 状态 => 中文名，后台展示用 */
    public static function labels(): array
    {
        return [
            self::DRAFT     => '草稿',
            self::REVIEW    => '待审',
            self::PUBLISHED => '已发布',
            self::ARCHIVED  => '已归档',
        ];
    }
}
