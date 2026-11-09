<?php
declare(strict_types=1);
namespace Wx\Systems\Member;

/**
 * 等级与经验规则存储协议。
 *
 * 等级是「经验区间」的有序边界，规则是「什么动作值多少经验、每天最多多少」。
 * 两者都完全由站长定义：可以改成 3 级、10 级、任意名字与任意门槛；
 * 系统只要求门槛严格递增、level 为正整数，其余不预设。
 */
interface LevelStoreInterface
{
    public function role(): string;

    /** 首次可用的基线等级与规则；已有数据不覆盖。 */
    public function seed(): void;

    /** @return list<array<string, mixed>> 按 min_xp 升序 */
    public function levels(): array;

    public function upsertLevel(int $level, string $name, int $minXp, string $color): bool;

    public function deleteLevel(int $level): bool;

    /** @return array<string, mixed>|null */
    public function rule(string $action): ?array;

    /** @return list<array<string, mixed>> */
    public function rules(): array;

    /** $dailyCap = 0 表示不限制；$enabled=false 时该动作不再产生经验。 */
    public function upsertRule(string $action, int $amount, int $dailyCap, bool $enabled, string $description): bool;

    public function deleteRule(string $action): bool;
}
