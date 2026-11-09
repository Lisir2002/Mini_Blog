<?php
declare(strict_types=1);
namespace Wx\Systems\Member;

/**
 * 成长档案的内存实现 —— 拔掉 MySQL 时成员系统仍可启动、可演示、可测试。
 *
 * 幂等语义与 PDO 实现一致：event_key 已存在即视为重放，绝不二次加分。
 * 内存版不跨请求存活（每个请求一个内核），文档中已明确标注该差异。
 */
final class MemoryProfileStore implements ProfileStoreInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $profiles = [];
    /** @var array<string, bool> */
    private array $events = [];
    /** @var array<string, int> */
    private array $daily = [];

    public function role(): string { return 'memory_profile_store'; }

    public function ensureSchema(): void {}

    public function profile(string $userId): ?array
    {
        return $this->profiles[$userId] ?? null;
    }

    public function awardXp(string $userId, string $action, int $amount, string $eventKey, string $note): array
    {
        if (isset($this->events[$eventKey])) {
            return ['duplicate' => true, 'applied' => 0, 'total' => $this->xpOf($userId)];
        }
        $this->events[$eventKey] = true;
        $this->profiles[$userId] = [
            'user_id'    => $userId,
            'xp'         => $this->xpOf($userId) + $amount,
            'level'      => (int) ($this->profiles[$userId]['level'] ?? 1),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        $slot = $userId . '|' . $action . '|' . date('Y-m-d');
        $this->daily[$slot] = ($this->daily[$slot] ?? 0) + $amount;
        return ['duplicate' => false, 'applied' => $amount, 'total' => $this->xpOf($userId)];
    }

    public function xpToday(string $userId, string $action, string $date): int
    {
        return (int) ($this->daily[$userId . '|' . $action . '|' . $date] ?? 0);
    }

    public function leaderboard(int $limit, int $offset): array
    {
        $rows = array_values($this->profiles);
        usort($rows, static fn(array $a, array $b): int =>
            ((int) $b['xp'] <=> (int) $a['xp']) ?: strcmp((string) $a['user_id'], (string) $b['user_id'])
        );
        return array_slice($rows, $offset, $limit);
    }

    public function setLevel(string $userId, int $level): void
    {
        if (!isset($this->profiles[$userId])) {
            $this->profiles[$userId] = ['user_id' => $userId, 'xp' => 0, 'level' => 1,
                'updated_at' => date('Y-m-d H:i:s')];
        }
        $this->profiles[$userId]['level'] = max((int) $this->profiles[$userId]['level'], $level);
        $this->profiles[$userId]['updated_at'] = date('Y-m-d H:i:s');
    }

    private function xpOf(string $userId): int
    {
        return (int) ($this->profiles[$userId]['xp'] ?? 0);
    }
}
