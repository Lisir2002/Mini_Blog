<?php
declare(strict_types=1);
namespace Wx\Systems\User;

use Wx\Contracts\DatabaseInterface;

/**
 * 令牌吊销 —— 账号状态变更必须立刻断链，不能等令牌自然过期。
 *
 * 这里有一处刻意的跨层写：`user_tokens` 归安全层所有，本类却直接 UPDATE 它。
 * 理由是安全不变量优先于表所有权 ——
 *   - 若改成「发一个 user.revoked 钩子让安全层订阅」，安全层缺席时钩子静默无操作，
 *     禁用账号就变成「登录被拒但旧令牌照样能调接口」，这是最坏的一种安静地错；
 *   - 若改成每次鉴权都 join 账号表查 status，读路径的成本会落到每个请求上。
 * 因此在「账号状态变更」这个低频写路径上主动清理，读路径零改动，且失败会抛异常。
 *
 * 表名与安全层保持同一前缀约定（prefix + user_tokens），不复制迁移。
 */
final class AccountRevocation
{
    public function __construct(private DatabaseInterface $db) {}

    /** 吊销某个账号的全部未撤销令牌，返回受影响条数。 */
    public function all(int $userId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE ' . $this->table() . ' SET revoked = 1 WHERE user_id = :id AND revoked = 0'
        );
        $stmt->execute([':id' => $userId]);
        return $stmt->rowCount();
    }

    /**
     * 吊销除当前令牌外的全部令牌 —— 改密后「其他设备下线，当前会话保留」。
     * $keepHash 为空时退化为 all()，不会出现「保留了一个不存在的令牌」这种悬空语义。
     */
    public function others(int $userId, string $keepHash): int
    {
        if ($keepHash === '') return $this->all($userId);
        $stmt = $this->db->pdo()->prepare(
            'UPDATE ' . $this->table() . ' SET revoked = 1 WHERE user_id = :id AND token_hash <> :hash AND revoked = 0'
        );
        $stmt->execute([':id' => $userId, ':hash' => $keepHash]);
        return $stmt->rowCount();
    }

    /** 登出：只撤销当前这一枚令牌。 */
    public function one(string $tokenHash): bool
    {
        if ($tokenHash === '') return false;
        $stmt = $this->db->pdo()->prepare(
            'UPDATE ' . $this->table() . ' SET revoked = 1 WHERE token_hash = :hash AND revoked = 0'
        );
        $stmt->execute([':hash' => $tokenHash]);
        return $stmt->rowCount() > 0;
    }

    private function table(): string { return $this->db->prefix() . 'user_tokens'; }
}
