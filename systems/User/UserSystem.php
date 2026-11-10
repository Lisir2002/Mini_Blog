<?php
declare(strict_types=1);
namespace Wx\Systems\User;

use Wx\Contracts\ApiAuthenticatorInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Contracts\UserProviderInterface;
use Wx\Core\AbstractSystem;
use Wx\Core\Kernel;
use Wx\Systems\Api\ApiOperationException;
use Wx\Systems\Data\Db;

/**
 * 用户系统：只提供身份契约与业务钩子，不含登录页、不含任何 HTML。
 * 接上 MySQL 走 PdoUserProvider（含令牌签发凭据校验），拔掉库回退会话适配器。
 */
final class UserSystem extends AbstractSystem
{
    public function name(): string { return 'user'; }

    public function register(Kernel $kernel): void
    {
        $kernel->container()->singleton(
            UserProviderInterface::class,
            static function () use ($kernel): UserProviderInterface {
                $db = Db::optional($kernel);
                return $db === null
                    ? new SessionUserProvider()
                    : new PdoUserProvider($db);
            },
            'system:user',
            10
        );

        // 业务钩子由系统层自行声明，核心并不知道 user.resolve 的存在
        $kernel->hooks()->filter('user.resolve', static function (?array $user): ?array {
            if ($user !== null) {
                $user['capabilities'] = $user['capabilities'] ?? ['read'];
            }
            return $user;
        }, 10);

        // 身份查询是跨层的公开装配点：成员 / 评论 / 主题都可能只知道「用户名」。
        // 由用户系统统一解释标识（数字按 ID、其余按用户名），其它层不必认识用户表。
        $kernel->hooks()->filter('user.lookup', static function (?array $user, string $key) use ($kernel): ?array {
            if ($user !== null || $key === '') return $user;
            $provider = $kernel->container()->has(UserProviderInterface::class)
                ? $kernel->container()->resolve(UserProviderInterface::class) : null;
            $found = $provider?->find(ctype_digit($key) ? (int) $key : $key);
            if ($found === null && $provider instanceof PdoUserProvider) {
                $found = $provider->findByUsername($key);
            }
            return $found;
        }, 10);
    }

    public function boot(Kernel $kernel): void
    {
        $provider = $kernel->container()->resolve(UserProviderInterface::class);
        if ($provider instanceof PdoUserProvider) {
            $provider->ensureSchema();
            $provider->seed();
        }

        // 账号存量自报给统计层：只给一个数字，不给任何账号字段。
        $kernel->hooks()->filter('metric.collect', static function (array $samples) use ($provider): array {
            $samples[] = ['name' => 'user_accounts', 'labels' => [], 'value' => $provider->count()];
            return $samples;
        }, 10);

        if (!$kernel->container()->has(ApiRegistryInterface::class)) return;

        /** @var ApiRegistryInterface $registry */
        $registry = $kernel->container()->resolve(ApiRegistryInterface::class);
        $auth = $kernel->container()->has(ApiAuthenticatorInterface::class)
            ? $kernel->container()->resolve(ApiAuthenticatorInterface::class)
            : null;

        // 凭据换令牌：明文口令只在此处出现一次，库里只存 password_hash；令牌库里只存 hash
        $registry->registerOperation('auth.token', static function (array $in) use ($provider, $auth): array {
            if (!$provider instanceof PdoUserProvider || $auth === null) {
                throw new ApiOperationException('未接入 MySQL 用户存储或鉴权契约，无法签发令牌', 50101);
            }
            $username = (string) ($in['username'] ?? '');
            $password = (string) ($in['password'] ?? '');
            $user = $provider->verifyCredentials($username, $password);
            if ($user === null) {
                throw new ApiOperationException('用户名或口令不正确', 40102);
            }
            $issued = $auth->issue($user['id'], (string) $user['username'], (string) $user['role']);
            return ['token' => $issued['token'], 'expires_at' => $issued['expires_at'],
                    'role' => $issued['role'], 'token_type' => 'Bearer'];
        });

        // 当前身份：由分发器把鉴权结果注入 _identity，业务执行者无需自己解析请求头。
        // user.profile.enrich 是公开扩展点（v0.10 起成员系统在此追加经验 / 等级 / 头衔），
        // 用户系统不认识任何具体订阅者，也不因订阅者缺席而改变自身输出。
        $registry->registerOperation('users.me', static function (array $in) use ($kernel): array {
            $identity = $in['_identity'] ?? null;
            return ['identity' => $identity === null
                ? null
                : $kernel->hooks()->apply('user.profile.enrich', $identity, $kernel)];
        });
    }
}
