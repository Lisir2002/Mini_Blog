<?php
declare(strict_types=1);
namespace Wx\Systems\User;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Kernel;
use Wx\Systems\Api\ApiOperationException;

/**
 * 管理端账号操作 —— 列表、详情、禁用 / 启用 / 重置口令 / 改角色。
 *
 * 这里有两条防呆不变量，都属于「一次误操作会把站点锁死」的类型：
 *   1. 不能禁用自己 —— 否则管理员点一下就把自己关在门外；
 *   2. 不能移除最后一个管理员 —— 否则站点再没有任何账号能进入管理端。
 * 两者都必须显式报错（40906），而不是靠前端把按钮置灰。
 */
final class AdminOperations
{
    public function __construct(
        private ?UserAccountStore $store,
        private ?AccountRevocation $revocation,
        private CredentialPolicy $policy,
        private Kernel $kernel
    ) {}

    public function role(): string { return 'admin_operations'; }

    public function register(ApiRegistryInterface $registry): void
    {
        $registry->registerOperation('users.list', function (array $in): array {
            $store = $this->requireStore();
            $limit  = max(1, min(200, (int) ($in['limit'] ?? 50)));
            $offset = max(0, (int) ($in['offset'] ?? 0));
            $status = (string) ($in['status'] ?? '');
            if ($status !== '' && !in_array($status, [UserAccountStore::STATUS_ACTIVE, UserAccountStore::STATUS_DISABLED], true)) {
                throw new ApiOperationException('status 只能是 active 或 disabled', 42201);
            }
            $page = $store->paginate($limit, $offset, $status);
            return [
                'items' => $page['items'],
                'total' => $page['total'],
                'limit' => $limit,
                'offset' => $offset,
                'truncated' => $offset + count($page['items']) < $page['total'],
            ];
        });

        $registry->registerOperation('users.view', function (array $in): array {
            $store = $this->requireStore();
            $key = trim((string) ($in['key'] ?? ''));
            if ($key === '') throw new ApiOperationException('key 必填（账号 ID 或用户名）', 42201);
            $user = ctype_digit($key) ? $store->find((int) $key) : null;
            if ($user === null) {
                $provider = $this->kernel->container()->has(\Wx\Contracts\UserProviderInterface::class)
                    ? $this->kernel->container()->resolve(\Wx\Contracts\UserProviderInterface::class) : null;
                $user = $provider instanceof PdoUserProvider ? $provider->findByUsername($key) : null;
            }
            if ($user === null) throw new ApiOperationException('账号不存在：' . $key, 40403);
            return ['user' => $user];
        });

        $registry->registerOperation('users.manage', function (array $in): array {
            $store = $this->requireStore();
            $identity = $in['_identity'] ?? null;
            if (!is_array($identity)) throw new ApiOperationException('需要登录', 40101);

            $target = $this->target((int) ($in['user_id'] ?? 0));
            $action = (string) ($in['action'] ?? '');
            $targetId = (int) $target['id'];
            $selfId = (int) ($identity['user_id'] ?? 0);

            return match ($action) {
                'disable' => $this->disable($target, $selfId),
                'enable'  => $this->enable($target),
                'set_role' => $this->setRole($target, $selfId, (string) ($in['role'] ?? '')),
                'reset_password' => $this->resetPassword($target, (string) ($in['new_password'] ?? '')),
                default => throw new ApiOperationException('action 只能是 disable / enable / set_role / reset_password', 42201),
            };
        });
    }

    /** @param array<string, mixed> $target @return array<string, mixed> */
    private function disable(array $target, int $selfId): array
    {
        $id = (int) $target['id'];
        if ($id === $selfId) {
            throw new ApiOperationException('不能禁用当前登录的账号', 40906);
        }
        $this->guardLastAdmin($target, '禁用');
        $this->store?->setStatus($id, UserAccountStore::STATUS_DISABLED);
        // 禁用必须立刻断链：否则旧令牌还能用，禁用只是「登录被拒」的假动作。
        $revoked = $this->revocation?->all($id) ?? 0;
        return ['user' => $this->store?->find($id) ?? $target, 'revoked_tokens' => $revoked];
    }

    /** @param array<string, mixed> $target @return array<string, mixed> */
    private function enable(array $target): array
    {
        $id = (int) $target['id'];
        $this->store?->setStatus($id, UserAccountStore::STATUS_ACTIVE);
        return ['user' => $this->store?->find($id) ?? $target, 'revoked_tokens' => 0];
    }

    /** @param array<string, mixed> $target @return array<string, mixed> */
    private function setRole(array $target, int $selfId, string $role): array
    {
        if (!UserAccountStore::validRole($role)) {
            throw new ApiOperationException('角色名只能是 2~32 位小写字母、数字或下划线', 42201);
        }
        $id = (int) $target['id'];
        if ((string) $target['role'] === $role) return ['user' => $target, 'changed' => false];
        if ($id === $selfId) {
            throw new ApiOperationException('不能修改当前登录账号的角色', 40906);
        }
        if ($role !== 'admin') $this->guardLastAdmin($target, '降级');
        $this->store?->setRole($id, $role);
        return ['user' => $this->store?->find($id) ?? $target, 'changed' => true];
    }

    /** @param array<string, mixed> $target @return array<string, mixed> */
    private function resetPassword(array $target, string $password): array
    {
        $username = (string) ($target['username'] ?? '');
        if (($error = $this->policy->passwordError($username, $password)) !== null) {
            throw new ApiOperationException($error, 42201);
        }
        $id = (int) $target['id'];
        $this->store?->changePassword($id, $password);
        // 管理员重置口令意味着「原主可能已失去控制」，全部令牌一并作废。
        $revoked = $this->revocation?->all($id) ?? 0;
        return ['user' => $this->store?->find($id) ?? $target, 'revoked_tokens' => $revoked];
    }

    /** @param array<string, mixed> $target */
    private function guardLastAdmin(array $target, string $verb): void
    {
        if ((string) $target['role'] !== 'admin') return;
        if (($this->store?->countRole('admin') ?? 0) > 1) return;
        throw new ApiOperationException('该操作会移除最后一个管理员，已拒绝', 40906);
    }

    /** @return array<string, mixed> */
    private function target(int $userId): array
    {
        if ($userId <= 0) throw new ApiOperationException('user_id 必填', 42201);
        $user = $this->store?->find($userId);
        if ($user === null) throw new ApiOperationException('账号不存在', 40403);
        return $user;
    }

    private function requireStore(): UserAccountStore
    {
        if ($this->store === null || $this->revocation === null) {
            throw new ApiOperationException('未接入 MySQL 用户存储，账号管理不可用', 50101);
        }
        return $this->store;
    }
}
