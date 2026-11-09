<?php
declare(strict_types=1);
namespace Wx\Systems\Member;

/**
 * 成长档案存储协议 —— 系统层内部契约（不是核心契约，核心不认识「经验」这个词）。
 *
 * 只承载「一个人的成长刻度」：经验余额、经验流水、排行榜、当前等级。
 * 等级门槛与经验规则属于 LevelStoreInterface，头衔属于 TitleStoreInterface，
 * 三者拆开是因为它们的读写者、变更频率与失效范围完全不同。
 *
 * 流水的唯一性由存储层保证（event_key 唯一索引），因此重放不会刷经验。
 */
interface ProfileStoreInterface
{
    public function role(): string;

    /** 幂等建表；内存实现为空操作。 */
    public function ensureSchema(): void;

    /** @return array<string, mixed>|null */
    public function profile(string $userId): ?array;

    /**
     * 记一笔经验并累加到余额。
     * 返回 duplicate=true 表示 event_key 已存在、本次未加分（幂等重放）。
     *
     * @return array{duplicate: bool, applied: int, total: int}
     */
    public function awardXp(string $userId, string $action, int $amount, string $eventKey, string $note): array;

    /** 某用户某动作在指定自然日已获得的经验合计（每日上限的判定依据）。 */
    public function xpToday(string $userId, string $action, string $date): int;

    /** @return list<array<string, mixed>> 按经验降序，user_id 升序保证稳定 */
    public function leaderboard(int $limit, int $offset): array;

    /** 只升不降：等级回退会让人「越写越低」，与成长语义矛盾。 */
    public function setLevel(string $userId, int $level): void;
}
