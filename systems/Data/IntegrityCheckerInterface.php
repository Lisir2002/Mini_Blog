<?php
declare(strict_types=1);
namespace Wx\Systems\Data;

/**
 * 完整性巡检契约 —— 没有外键时，用它替代数据库替你做的孤儿检查。
 *
 * 这是「不加外键」这个决定的补偿控制，而不是补救：跨系统解耦要求表之间不设硬约束，
 * 那么约束必须由巡检 + 守卫接管，否则「解耦」就只是「没人管」。
 */
interface IntegrityCheckerInterface
{
    /**
     * @return array{ok: bool, skipped: bool, checked: int, findings: list<array{
     *     relation: string, table: string, count: int, samples: list<int>
     * }>}
     */
    public function inspect(): array;
}
