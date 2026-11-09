<?php
declare(strict_types=1);
namespace Wx\Systems\State;

/**
 * 状态存储契约 —— 跨请求的长任务状态载体。
 *
 * 只定义「怎么存」：put 用 revision 做乐观并发，find 读回，sweep 清理过期。
 * 「能不能从 done 退回 running」属于状态规则，由 StateTasks 裁决，存储层不参与。
 */
interface StateStoreInterface
{
    /** revision=0 视为新建；非 0 时按 CAS 更新，版本不匹配必须抛 40901。 */
    public function put(State $state, string $kind = '', string $owner = '', int $ttl = 0): State;

    public function find(string $id): ?State;

    /** @return int 清理掉的过期条数 */
    public function sweep(int $now): int;

    public function ensureSchema(): void;
}
