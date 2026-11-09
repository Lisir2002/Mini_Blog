<?php
declare(strict_types=1);
namespace Wx\Systems\Security;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Contracts\UserProviderInterface;
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
        private ?UserProviderInterface $users
    ) {}

    public function role(): string { return 'session_operations'; }

    public function register(ApiRegistryInterface $registry): void
    {
        $registry->registerOperation('auth.session.create', function (array $in): array {
            $users = $this->users;
            if ($users === null || !method_exists($users, 'verifyCredentials')) {
                throw new ApiOperationException('未接入 MySQL 用户存储，无法创建会话', 50101);
            }
            $user = $users->verifyCredentials(
                (string) ($in['username'] ?? ''), (string) ($in['password'] ?? '')
            );
            if (!is_array($user)) {
                throw new ApiOperationException('用户名或口令不正确', 40102);
            }
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
}
