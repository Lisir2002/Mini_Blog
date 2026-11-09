<?php
declare(strict_types=1);
namespace Wx\Systems\Security;

use RuntimeException;
use Wx\Contracts\ApiAuthenticatorInterface;
use Wx\Contracts\DatabaseInterface;
use Wx\Systems\Database\Schema;

/**
 * 接口鉴权的默认实现 —— auth 从声明式标记变成真实执行。
 * 令牌落 MySQL（只存 SHA-256 摘要）、带过期与撤销；也支持配置里的静态令牌用于本地演示。
 * 可被任意插件用更高 priority 换成 OAuth / 签名校验 / 企业 SSO。
 */
final class DbApiAuthenticator implements ApiAuthenticatorInterface
{
    /** @param array<string, mixed> $staticTokens token => 身份描述（仅本地演示 / 测试） */
    public function __construct(
        private ?DatabaseInterface $db = null,
        private array $staticTokens = []
    ) {}

    public function ensureSchema(): void
    {
        if ($this->db !== null) Schema::apply($this->db, __DIR__ . '/migrations');
    }

    public function authenticate(string $token): ?array
    {
        if ($token === '') return null;
        if (isset($this->staticTokens[$token])) {
            $cfg = (array) $this->staticTokens[$token];
            return [
                'user_id'  => $cfg['user_id']  ?? 0,
                'username' => (string) ($cfg['username'] ?? 'static'),
                'role'     => (string) ($cfg['role'] ?? 'admin'),
                'scopes'   => (array) ($cfg['scopes'] ?? []),
                'source'   => 'static',
                'kind'     => 'static',
                // 静态令牌不在令牌表里，没有可撤销的摘要；登出接口据此直接返回 false。
                'token_hash' => '',
            ];
        }
        if ($this->db === null) return null;

        $hash = hash('sha256', $token);
        $stmt = $this->db->pdo()->prepare(
            'SELECT user_id,username,role,scopes,kind,expires_at FROM ' . $this->table('user_tokens')
            . ' WHERE token_hash = :hash AND revoked = 0 LIMIT 1'
        );
        $stmt->execute([':hash' => $hash]);
        $row = $stmt->fetch();
        if ($row === false || strtotime((string) $row['expires_at']) < time()) return null;
        return [
            'user_id'  => (int) $row['user_id'],
            'username' => (string) $row['username'],
            'role'     => (string) $row['role'],
            'scopes'   => array_values(array_filter(explode(',', (string) $row['scopes']))),
            'source'   => 'database',
            'kind'     => (string) ($row['kind'] ?? 'api'),
            'token_hash' => $hash,
        ];
    }

    public function allows(string $level, ?array $identity): bool
    {
        return match ($level) {
            '', 'public' => true,
            'user'       => $identity !== null,
            'admin'      => $identity !== null && ($identity['role'] ?? '') === 'admin',
            default      => false,
        };
    }

    public function issue(int|string $userId, string $username, string $role, int $ttl = 3600, string $kind = 'api'): array
    {
        if ($this->db === null) {
            throw new RuntimeException('未接入 MySQL 令牌表，无法签发落库令牌');
        }
        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + max(60, $ttl));
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO ' . $this->table('user_tokens')
            . ' (token_hash,user_id,username,role,scopes,kind,expires_at,created_at)
               VALUES (:hash,:user_id,:username,:role,:scopes,:kind,:expires_at,:created_at)'
        );
        $stmt->execute([
            ':hash' => hash('sha256', $token), ':user_id' => (int) $userId,
            ':username' => $username, ':role' => $role, ':scopes' => '',
            ':kind' => $kind === 'session' ? 'session' : 'api',
            ':expires_at' => $expires, ':created_at' => date('Y-m-d H:i:s'),
        ]);
        return ['token' => $token, 'expires_at' => $expires, 'role' => $role, 'kind' => $kind];
    }

    private function table(string $name): string
    {
        return $this->db === null ? '' : $this->db->prefix() . $name;
    }
}
