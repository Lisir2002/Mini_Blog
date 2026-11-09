<?php
declare(strict_types=1);
namespace Wx\Systems\Security;

use Wx\Contracts\DatabaseInterface;
use Wx\Contracts\SecurityGuardInterface;
use Wx\Systems\Data\Schema;

/**
 * 默认防护实现：输出转义 + CSRF 令牌。
 * 令牌落库、带过期、一次性使用（v0.1 的进程内静态字符串已废弃）；无库时回退进程内比较。
 */
final class DefaultGuard implements SecurityGuardInterface
{
    private ?string $token = null;

    public function __construct(private ?DatabaseInterface $db = null) {}

    public function ensureSchema(): void
    {
        if ($this->db !== null) Schema::apply($this->db, __DIR__ . '/migrations');
    }

    public function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function token(): string
    {
        if ($this->token === null) {
            $this->token = bin2hex(random_bytes(16));
            if ($this->db !== null) {
                $stmt = $this->db->pdo()->prepare(
                    'INSERT INTO ' . $this->table() . ' (token_hash,expires_at,created_at)
                     VALUES (:hash,:expires_at,:created_at)'
                );
                $stmt->execute([
                    ':hash' => hash('sha256', $this->token),
                    ':expires_at' => date('Y-m-d H:i:s', time() + 1800),
                    ':created_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
        return $this->token;
    }

    public function verify(string $token): bool
    {
        if ($this->db === null) {
            return hash_equals($this->token(), $token);
        }
        $hash = hash('sha256', $token);
        // 时间统一由 PHP 生成与比较：避免 PHP 时区与 MySQL NOW() 不一致导致的双时钟陷阱
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM ' . $this->table() . ' WHERE token_hash = :hash AND used = 0 AND expires_at > :now LIMIT 1'
        );
        $stmt->execute([':hash' => $hash, ':now' => date('Y-m-d H:i:s')]);
        $id = $stmt->fetchColumn();
        if ($id === false) return false;
        $this->db->pdo()->prepare('UPDATE ' . $this->table() . ' SET used = 1 WHERE id = :id')
                 ->execute([':id' => (int) $id]);
        // 一次性语义：校验通过即作废当前缓存，同一请求内再次 token() 会签发新令牌。
        // 否则「提交失败后重新渲染表单」会把已用过的令牌再发一次，用户重试必然失败。
        $this->token = null;
        return true;
    }

    private function table(): string
    {
        return $this->db === null ? '' : $this->db->prefix() . 'csrf_tokens';
    }
}
