<?php
declare(strict_types=1);
namespace Wx\Systems\Lifecycle;

use Wx\Core\HookBus;

/**
 * 状态机协作者 —— 只回答一个问题：某个状态下允不允许做某个操作。
 * 不碰文件、不碰清单、不持久化；裁决结果由调用方去执行。
 */
final class StateMachine
{
    private const TRANSITIONS = [
        'install'    => ['absent'],
        'activate'   => ['installed'],
        'deactivate' => ['active'],
        'upgrade'    => ['installed', 'active'],
        'uninstall'  => ['installed'],
        'rollback'   => ['installed', 'active'],
    ];

    public function __construct(private HookBus $hooks) {}

    public function role(): string { return 'state_machine'; }

    /** @return array<string, list<string>> 供守卫测试核对转换表本身 */
    public function table(): array { return self::TRANSITIONS; }

    public function can(string $state, string $operation): bool
    {
        return in_array($state, self::TRANSITIONS[$operation] ?? [], true);
    }

    public function assertTransition(string $state, string $operation, string $key): void
    {
        $this->hooks->emit('lifecycle.collab', $this->role(), $operation);
        if (!isset(self::TRANSITIONS[$operation])) {
            throw new LifecycleException('未知装卸操作：' . $operation, 42201);
        }
        if (!$this->can($state, $operation)) {
            throw new LifecycleException(
                "状态机拒绝：{$key} 当前为 {$state}，不能执行 {$operation}", 40901
            );
        }
    }
}
