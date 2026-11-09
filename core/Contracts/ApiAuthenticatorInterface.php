<?php
declare(strict_types=1);
namespace Wx\Contracts;

/**
 * 接口鉴权契约 —— 让清单里的 auth 从「声明式标记」变成可替换的真实执行。
 *
 * 默认实现由安全系统提供（令牌落库 + 过期 + 角色）；任意系统层 / 插件
 * 可用更高 priority 覆盖成 OAuth、签名校验或企业 SSO。核心不认任何鉴权算法。
 */
interface ApiAuthenticatorInterface
{
    /**
     * @return array{user_id: int|string, username: string, role: string, scopes: list<string>}|null
     *         令牌无效 / 过期 / 已撤销时返回 null
     */
    public function authenticate(string $token): ?array;

    /** 判定身份是否满足接口清单声明的鉴权级别（public / user / admin） */
    public function allows(string $level, ?array $identity): bool;

    /** @return array{token: string, expires_at: string, role: string} */
    public function issue(int|string $userId, string $username, string $role, int $ttl = 3600): array;
}
