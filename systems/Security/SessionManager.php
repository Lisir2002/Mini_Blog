<?php
declare(strict_types=1);
namespace Wx\Systems\Security;

use Wx\Contracts\DatabaseInterface;

/**
 * 页面会话 —— 与 API 令牌共用同一张表、同一套过期与撤销语义，差别只在「载体」。
 *
 * 这样做的理由：
 *   - 不发明第二套身份体系：会话令牌照样能被 ApiAuthenticatorInterface 校验，
 *     页面守卫不需要认识「会话」这个概念，只多认一个 Cookie 名；
 *   - 不复制撤销逻辑：登出就是 `revoked = 1`，与 API 令牌撤销同一条 SQL 路径；
 *   - 可审计：`kind = 'session'` 能区分「浏览器会话」与「脚本令牌」，便于安全排查。
 *
 * 载体安全由安全层负责：HttpOnly + SameSite=Lax 的 Cookie 只用于页面 GET，
 * API 写操作仍要求 Authorization 头，因此不存在「Cookie 自动携带导致 CSRF」的面。
 */
final class SessionManager
{
    public function __construct(
        private DatabaseInterface $db,
        private DbApiAuthenticator $auth,
        private int $ttl = 7200
    ) {}

    public function role(): string { return 'session_manager'; }

    /** @return array<string, mixed> token / expires_at / role / max_age / cookie_name */
    public function issue(int|string $userId, string $username, string $role): array
    {
        $issued = $this->auth->issue($userId, $username, $role, $this->ttl, 'session');
        return $issued + ['max_age' => $this->ttl, 'cookie_name' => 'wx_session'];
    }

    /** 只撤销会话令牌：拿到 API 令牌的 hash 也不会误伤脚本凭据。 */
    public function revoke(string $tokenHash): bool
    {
        if ($tokenHash === '') return false;
        $stmt = $this->db->pdo()->prepare(
            "UPDATE " . $this->table() . " SET revoked = 1 WHERE token_hash = ? AND kind = 'session'"
        );
        $stmt->execute([$tokenHash]);
        return $stmt->rowCount() > 0;
    }

    /** @return array<string, mixed>|null 会话元数据（不含令牌明文） */
    public function describe(string $tokenHash): ?array
    {
        if ($tokenHash === '') return null;
        $stmt = $this->db->pdo()->prepare(
            'SELECT user_id,username,role,kind,expires_at,created_at FROM ' . $this->table()
            . ' WHERE token_hash = ? AND revoked = 0 LIMIT 1'
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch();
        if ($row === false) return null;
        return [
            'user_id'    => (int) $row['user_id'],
            'username'   => (string) $row['username'],
            'role'       => (string) $row['role'],
            'kind'       => (string) $row['kind'],
            'expires_at' => (string) $row['expires_at'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    private function table(): string { return $this->db->prefix() . 'user_tokens'; }
}
