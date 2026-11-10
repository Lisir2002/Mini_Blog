<?php
declare(strict_types=1);
namespace Wx\Systems\User;

use Wx\Contracts\ApiAuthenticatorInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Kernel;
use Wx\Systems\Api\ApiOperationException;

/**
 * 自助账号操作 —— 注册、改密、登出、改资料。
 *
 * 四个操作的共同点：主体是「本人」。改别人账号需要管理权限，放在 AdminOperations，
 * 两边的权限级别不同，混在一个类里迟早会把「改自己」的宽松规则漏到「改别人」上。
 */
final class AccountOperations
{
    public function __construct(
        private ?UserAccountStore $store,
        private ?AccountRevocation $revocation,
        private CredentialPolicy $policy,
        private ?ApiAuthenticatorInterface $auth,
        private Kernel $kernel
    ) {}

    public function role(): string { return 'account_operations'; }

    public function register(ApiRegistryInterface $registry): void
    {
        $registry->registerOperation('auth.register', function (array $in): array {
            $store = $this->requireStore();

            // 注册默认关闭：博客的账号通常由管理员创建或邀请，开放注册是一个
            // 需要站点主明确决定的安全决策，不能因为「接口存在」就默认敞开。
            if ($this->kernel->config()->get('user.registration.enabled', false) !== true) {
                throw new ApiOperationException('本站未开放注册', 40304);
            }

            $username = trim((string) ($in['username'] ?? ''));
            $password = (string) ($in['password'] ?? '');
            if (($error = $this->policy->usernameError($username)) !== null) {
                throw new ApiOperationException($error, 42201);
            }
            if (($error = $this->policy->passwordError($username, $password)) !== null) {
                throw new ApiOperationException($error, 42201);
            }

            $result = $store->register($username, $password, (string) ($in['display_name'] ?? ''));
            if (!$result['ok']) {
                // 用户名被占用是客户端可修正的状态，不是服务端故障。
                throw new ApiOperationException('用户名已被占用：' . $username, 40907);
            }
            $user = (array) ($result['user'] ?? []);
            // 注册即登录：直接签发令牌，省掉一次「刚设完口令再输一遍」。
            $issued = $this->auth?->issue((int) ($user['id'] ?? 0), $username, (string) ($user['role'] ?? 'subscriber'));
            return [
                'user' => $user,
                'token' => $issued['token'] ?? '',
                'expires_at' => $issued['expires_at'] ?? '',
                'token_type' => 'Bearer',
            ];
        });

        $registry->registerOperation('auth.password.change', function (array $in): array {
            $store = $this->requireStore();
            $identity = $this->identity($in);
            $userId = (int) ($identity['user_id'] ?? 0);

            $old = (string) ($in['old_password'] ?? '');
            $new = (string) ($in['new_password'] ?? '');
            $currentHash = $store->passwordHash($userId);
            if ($currentHash === null) throw new ApiOperationException('账号不存在', 40403);
            if (!password_verify($old, $currentHash)) {
                throw new ApiOperationException('旧口令不正确', 40105);
            }
            if ($old === $new) {
                // 「改成一样」看起来成功了，实际口令没变，用户会以为已经换过。
                throw new ApiOperationException('新口令不能与旧口令相同', 42201);
            }
            if (($error = $this->policy->passwordError((string) ($identity['username'] ?? ''), $new)) !== null) {
                throw new ApiOperationException($error, 42201);
            }

            $store->changePassword($userId, $new);
            // 改密即下线其他设备：旧口令泄露时，这一步是唯一的止损动作。
            $revoked = $this->revocation?->others($userId, (string) ($identity['token_hash'] ?? '')) ?? 0;
            return ['changed' => true, 'revoked_tokens' => $revoked];
        });

        $registry->registerOperation('auth.logout', function (array $in): array {
            $identity = $this->identity($in);
            $hash = (string) ($identity['token_hash'] ?? '');
            // 静态令牌没有落库摘要，无法撤销；这里如实返回 false，而不是假装登出成功。
            return ['revoked' => $this->revocation?->one($hash) ?? false, 'kind' => (string) ($identity['kind'] ?? '')];
        });

        $registry->registerOperation('users.me.update', function (array $in): array {
            $store = $this->requireStore();
            $identity = $this->identity($in);
            $userId = (int) ($identity['user_id'] ?? 0);
            $display = trim((string) ($in['display_name'] ?? ''));
            if ($display === '') throw new ApiOperationException('显示名称不能为空', 42201);
            $length = UserAccountStore::textLength($display);
            if ($length < 0) throw new ApiOperationException('显示名称不是合法的 UTF-8', 42201);
            if ($length > 64) throw new ApiOperationException('显示名称不能超过 64 字', 42201);
            if (!$store->updateProfile($userId, ['display_name' => $display])) {
                throw new ApiOperationException('没有需要更新的字段', 42201);
            }
            $user = $store->find($userId) ?? [];
            return ['user' => $this->kernel->hooks()->apply('user.profile.enrich', $user, $this->kernel)];
        });
    }

    private function requireStore(): UserAccountStore
    {
        if ($this->store === null || $this->revocation === null) {
            throw new ApiOperationException('未接入 MySQL 用户存储，账号操作不可用', 50101);
        }
        return $this->store;
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    private function identity(array $in): array
    {
        $identity = $in['_identity'] ?? null;
        if (!is_array($identity)) throw new ApiOperationException('需要登录', 40101);
        return $identity;
    }
}
