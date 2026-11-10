<?php
declare(strict_types=1);
namespace Wx\Systems\Security;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Contracts\UserProviderInterface;
use Wx\Core\Kernel;
use Wx\Systems\Api\ApiOperationException;

/**
 * 页面会话的三个规范化接口 —— 登录签发、查询当前会话、登出撤销。
 *
 * 执行者只返回「令牌 + 元数据」，把令牌变成 HttpOnly Cookie 是安全层在 route.response
 * 里做的事（凭据载体属于安全层职责）。这样业务执行者不认识 Cookie，接口文档也不会
 * 把会话令牌明文当作「前端可读的返回值」推荐出去。
 */
final class SessionOperations
{
    public function __construct(
        private SessionManager $sessions,
        private ?UserProviderInterface $users,
        private Kernel $kernel
    ) {}

    public function role(): string { return 'session_operations'; }

    public function register(ApiRegistryInterface $registry): void
    {
        $registry->registerOperation('auth.session.create', function (array $in): array {
            $users = $this->users;
            if ($users === null || !method_exists($users, 'verifyCredentials')) {
                throw new ApiOperationException('未接入 MySQL 用户存储，无法创建会话', 50101);
            }
            $user = $this->authenticate(
                $users, (string) ($in['username'] ?? ''), (string) ($in['password'] ?? '')
            );
            return $this->sessions->issue(
                (int) ($user['id'] ?? 0),
                (string) ($user['username'] ?? ''),
                (string) ($user['role'] ?? 'subscriber')
            );
        });

        $registry->registerOperation('auth.session.current', function (array $in): array {
            $identity = $in['_identity'] ?? null;
            $hash     = (string) ($identity['token_hash'] ?? '');
            return [
                'identity' => $identity,
                'session'  => $this->sessions->describe($hash),
            ];
        });

        $registry->registerOperation('auth.session.revoke', function (array $in): array {
            $identity = $in['_identity'] ?? null;
            $hash     = (string) ($identity['token_hash'] ?? '');
            return ['revoked' => $this->sessions->revoke($hash)];
        });
    }

    /**
     * 页面登录与 API 登录必须共用同一套判定 —— 否则会出现「API 已被锁定，
     * 换浏览器页面照样能进」的旁路。这里优先调用账号存储的 attemptLogin，
     * 只有旧实现才退回 verifyCredentials。
     *
     * @param object $users 已确认具备凭据校验能力
     * @return array<string, mixed>
     */
    private function authenticate(object $users, string $username, string $password): array
    {
        if (method_exists($users, 'attemptLogin')) {
            $result = $users->attemptLogin(
                $username, $password,
                (int) $this->kernel->config()->get('user.login.max_attempts', 5),
                (int) $this->kernel->config()->get('user.login.lock_seconds', 900)
            );
            if (($result['ok'] ?? false) === true) return (array) $result['user'];
            throw match ($result['reason'] ?? 'invalid') {
                'locked' => new ApiOperationException(
                    '账号已临时锁定，请在 ' . (int) ($result['retry_after'] ?? 0) . ' 秒后重试', 42902
                ),
                'disabled' => new ApiOperationException('账号已被禁用', 40103),
                default => new ApiOperationException('用户名或口令不正确', 40105),
            };
        }

        $user = $users->verifyCredentials($username, $password);
        if (!is_array($user)) throw new ApiOperationException('用户名或口令不正确', 40105);
        return $user;
    }
}
