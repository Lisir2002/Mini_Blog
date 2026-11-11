<?php
declare(strict_types=1);
namespace Wx\Systems\Member;

use PDOException;
use Wx\Contracts\DatabaseInterface;
use Wx\Systems\Data\Schema;

/**
 * 成长档案的 MySQL / PDO 实现。
 *
 * 发经验是一个事务：写流水 + 累加余额必须同时成功。流水的 event_key 唯一索引是
 * 幂等的最终防线 —— 即使两个请求同时到达，也只有一个能插入成功，另一个按重放处理。
 */
final class PdoProfileStore implements ProfileStoreInterface
{
    public function __construct(private DatabaseInterface $db) {}

    public function role(): string { return 'pdo_profile_store'; }

    public function ensureSchema(): void
    {
        Schema::apply($this->db, __DIR__ . '/migrations');
    }

    public function profile(string $userId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT user_id,xp,level,updated_at FROM ' . $this->table('member_profiles') . ' WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function awardXp(string $userId, string $action, int $amount, string $eventKey, string $note): array
    {
        $pdo = $this->db->pdo();
        $now = date('Y-m-d H:i:s');
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'INSERT INTO ' . $this->table('member_xp_events')
                . ' (user_id,action,amount,event_key,note,created_at) VALUES (?,?,?,?,?,?)'
            );
            $stmt->execute([$userId, $action, $amount, $eventKey, $note, $now]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // 唯一键冲突 = 同一业务事件被重放，不是错误：返回当前余额，让调用方照常回包。
            if ((string) $e->getCode() === '23000') {
                return ['duplicate' => true, 'applied' => 0, 'total' => $this->xpOf($userId)];
            }
            throw $e;
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO ' . $this->table('member_profiles')
                . ' (user_id,xp,level,updated_at) VALUES (?,?,1,?)'
                . ' ON DUPLICATE KEY UPDATE xp = xp + VALUES(xp), updated_at = VALUES(updated_at)'
            );
            $stmt->execute([$userId, $amount, $now]);
            $total = $this->xpOf($userId);
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['duplicate' => false, 'applied' => $amount, 'total' => $total];
    }

    public function xpToday(string $userId, string $action, string $date): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(amount),0) FROM ' . $this->table('member_xp_events')
            . ' WHERE user_id = ? AND action = ? AND DATE(created_at) = ?'
        );
        $stmt->execute([$userId, $action, $date]);
        return (int) $stmt->fetchColumn();
    }

    public function leaderboard(int $limit, int $offset): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT user_id,xp,level FROM ' . $this->table('member_profiles')
            . ' ORDER BY xp DESC, user_id ASC LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function count(): int
    {
        $stmt = $this->db->pdo()->query('SELECT COUNT(*) FROM ' . $this->table('member_profiles'));
        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }

    public function setLevel(string $userId, int $level): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO ' . $this->table('member_profiles')
            . ' (user_id,xp,level,updated_at) VALUES (?,0,?,?)'
            . ' ON DUPLICATE KEY UPDATE level = GREATEST(level, VALUES(level)), updated_at = VALUES(updated_at)'
        );
        $stmt->execute([$userId, $level, date('Y-m-d H:i:s')]);
    }

    private function xpOf(string $userId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT xp FROM ' . $this->table('member_profiles') . ' WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $xp = $stmt->fetchColumn();
        return $xp === false ? 0 : (int) $xp;
    }

    private function table(string $name): string { return $this->db->prefix() . $name; }
}
