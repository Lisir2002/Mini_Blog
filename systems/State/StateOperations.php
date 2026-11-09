<?php
declare(strict_types=1);
namespace Wx\Systems\State;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Systems\Api\ApiOperationException;

/**
 * 状态层接口执行者 —— 只做一件事：按 id 读回长任务状态。
 * 执行者不认识 HTTP，也不判断身份：鉴权在 RequestGuard，权限在 permission 层。
 */
final class StateOperations
{
    public function __construct(private StateTasks $tasks) {}

    public function role(): string { return 'state_operations'; }

    public function register(ApiRegistryInterface $registry): void
    {
        $tasks = $this->tasks;
        $registry->registerOperation('state.task.status', static function (array $in) use ($tasks): array {
            $id = trim((string) ($in['id'] ?? ''));
            if ($id === '' || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) !== 1) {
                throw new ApiOperationException('id 只允许字母、数字、下划线或短横线', 42201);
            }
            $state = $tasks->find($id);
            if ($state === null) {
                throw new ApiOperationException('任务不存在：' . $id, 40403);
            }
            return $state->toArray();
        });
    }
}
