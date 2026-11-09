<?php
declare(strict_types=1);
namespace Wx\Systems\State;

/**
 * 长任务状态的内存实现 —— 无数据库时的降级载体，也是守卫测试的确定性夹具。
 *
 * 行数据是静态的：同一进程内多次解析该契约必须看到同一份状态，
 * 否则「跨请求推进」这条最关键的语义在降级模式下根本无法成立。
 */
final class MemoryStateStore implements StateStoreInterface
{
    /** @var array<string, array{state: State, expires: int}> */
    private static array $rows = [];

    public function role(): string { return 'memory_state_store'; }

    public function ensureSchema(): void {}

    public function put(State $state, string $kind = '', string $owner = '', int $ttl = 0): State
    {
        $id = (string) ($state->meta()['id'] ?? '');
        if ($id === '') throw new StateException('持久化状态必须携带 id', 42201);

        $stored = self::$rows[$id]['state'] ?? null;
        if ($state->revision() === 0 && $stored !== null) {
            throw new StateException('任务 id 已存在：' . $id, 40901);
        }
        if ($state->revision() > 0) {
            if ($stored === null) throw new StateException('任务不存在：' . $id, 40403);
            if ($stored->revision() !== $state->revision()) {
                throw new StateException('状态已被其他请求推进，本次不覆盖：' . $id, 40901);
            }
        }

        $next = $state->with(['revision' => max(1, $state->revision() + 1)]);
        self::$rows[$id] = ['state' => $next, 'expires' => $ttl > 0 ? time() + $ttl : 0];
        return $next;
    }

    public function find(string $id): ?State
    {
        $row = self::$rows[$id] ?? null;
        if ($row === null) return null;
        if ($row['expires'] > 0 && $row['expires'] < time()) {
            unset(self::$rows[$id]);
            return null;
        }
        return $row['state'];
    }

    public function sweep(int $now): int
    {
        $removed = 0;
        foreach (self::$rows as $id => $row) {
            if ($row['expires'] > 0 && $row['expires'] < $now) {
                unset(self::$rows[$id]);
                $removed++;
            }
        }
        return $removed;
    }
}
