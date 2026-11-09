<?php
declare(strict_types=1);
namespace Wx\Systems\Member;

use InvalidArgumentException;
use Wx\Core\HookBus;

/**
 * 经验发放用例 —— 幂等、限额、升级判定都在这里，存储只负责「记一笔」。
 *
 * 三条不可让步的规则：
 *   1) 幂等：event_key 标识「同一件业务事实」，重放绝不再加分；
 *   2) 限额：每日上限按自然日与动作统计，超出部分直接截断而不是报错；
 *   3) 只升不降：等级由经验推导，经验只增，因此等级不会回退。
 * 发放成功后广播 member.xp.awarded / member.level.changed，供主题、审计、缓存失效订阅。
 */
final class XpAwarder
{
    public function __construct(
        private ProfileStoreInterface $profiles,
        private LevelStoreInterface $levels,
        private HookBus $hooks
    ) {}

    public function role(): string { return 'xp_awarder'; }

    /**
     * @return array{applied: int, total: int, level: int, level_up: bool,
     *               duplicate: bool, reason: string}
     */
    public function award(string $userId, string $action, string $eventKey, string $note = '', ?int $amount = null): array
    {
        $userId   = trim($userId);
        $action   = trim($action);
        $eventKey = trim($eventKey);
        if ($userId === '' || $action === '' || $eventKey === '') {
            throw new InvalidArgumentException('user_id / action / event_key 均必填');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $action)) {
            throw new InvalidArgumentException('action 只能使用小写字母、数字、点、下划线、短横线');
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $eventKey)) {
            throw new InvalidArgumentException('event_key 格式非法');
        }

        $rule = $this->levels->rule($action);
        if ($rule !== null && (int) $rule['enabled'] !== 1) {
            return $this->snapshot($userId, 0, 'disabled');
        }
        $base = $amount ?? ($rule === null ? null : (int) $rule['amount']);
        if ($base === null || $base <= 0) {
            throw new InvalidArgumentException('未配置该动作的经验规则，需显式传入 amount');
        }

        $cap  = $rule === null ? 0 : max(0, (int) $rule['daily_cap']);
        $used = $this->profiles->xpToday($userId, $action, date('Y-m-d'));
        if ($cap > 0 && $used >= $cap) {
            return $this->snapshot($userId, 0, 'daily_cap');
        }
        $applied = $cap > 0 ? min($base, $cap - $used) : $base;

        $before = (int) ($this->profiles->profile($userId)['level'] ?? 1);
        $result = $this->profiles->awardXp($userId, $action, $applied, $eventKey, $note);
        if ($result['duplicate']) {
            return $this->snapshot($userId, 0, 'duplicate', true);
        }

        $level = LevelCurve::levelOf($this->levels->levels(), (int) $result['total']);
        $levelUp = $level['level'] > $before;
        if ($levelUp) {
            $this->profiles->setLevel($userId, $level['level']);
            $this->hooks->emit('member.level.changed', [
                'user_id' => $userId, 'from' => $before, 'to' => $level['level'], 'xp' => $result['total'],
            ]);
        }
        $this->hooks->emit('member.xp.awarded', [
            'user_id' => $userId, 'action' => $action, 'amount' => $result['applied'],
            'xp' => $result['total'], 'level' => $level['level'], 'event_key' => $eventKey,
        ]);

        return [
            'applied'   => (int) $result['applied'],
            'total'     => (int) $result['total'],
            'level'     => $level['level'],
            'level_up'  => $levelUp,
            'duplicate' => false,
            'reason'    => '',
        ];
    }

    /** 未加分时也要能给出当前余额与等级，前端不必再发一次查询。 */
    private function snapshot(string $userId, int $applied, string $reason, bool $duplicate = false): array
    {
        $row   = $this->profiles->profile($userId);
        $total = (int) ($row['xp'] ?? 0);
        $level = LevelCurve::levelOf($this->levels->levels(), $total);
        return [
            'applied' => $applied, 'total' => $total, 'level' => $level['level'],
            'level_up' => false, 'duplicate' => $duplicate, 'reason' => $reason,
        ];
    }
}
