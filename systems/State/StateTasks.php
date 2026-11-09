<?php
declare(strict_types=1);
namespace Wx\Systems\State;

/**
 * 长任务编排 —— 管「跨请求的那部分状态」，不碰存储细节。
 *
 * 两条规则在这里落地：
 *   1. 终态只能停在 done / failed，再推进一律 40901；
 *   2. 每次推进必须带上一版 revision，存储层用 CAS 拒绝并发覆盖。
 * 页面级与动作级状态是「用完即弃」的，不走这里，直接进 payload。
 */
final class StateTasks
{
    public function __construct(private StateStoreInterface $store, private int $ttl = 86400) {}

    public function open(string $kind, State $state, string $owner = ''): State
    {
        if ($state->scope() !== 'task') {
            throw new StateException('只有 scope=task 的状态可以持久化', 42201);
        }
        return $this->store->put($state, $kind, $owner, $this->ttl);
    }

    public function advance(State $next): State
    {
        $id = (string) ($next->meta()['id'] ?? '');
        if ($id === '') throw new StateException('推进状态必须携带任务 id', 42201);
        $current = $this->store->find($id);
        if ($current === null) throw new StateException('任务不存在：' . $id, 40403);
        if ($current->isTerminal()) {
            throw new StateException('任务已结束，不能再推进：' . $id, 40901);
        }
        return $this->store->put($next->with(['revision' => $current->revision()]), '', '', $this->ttl);
    }

    public function find(string $id): ?State
    {
        return $this->store->find($id);
    }

    public function sweep(): int
    {
        return $this->store->sweep(time());
    }
}
