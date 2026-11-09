<?php
declare(strict_types=1);
namespace Wx\Systems\Member;

use InvalidArgumentException;
use PDOException;
use Wx\Contracts\DatabaseInterface;

/**
 * 等级门槛与经验规则的 MySQL / PDO 实现。
 *
 * 门槛由站长定义，系统只保证结构合法：level 为正整数、min_xp 不为负、门槛不重复。
 * 删除某个等级不会回写用户档案 —— 等级是「按当前门槛重新解释经验」的结果，
 * 而不是一份独立状态，因此下次计算自然落到相邻等级（LevelCurve 负责）。
 */
final class PdoLevelStore implements LevelStoreInterface
{
    /** 默认 5 级：名字、门槛、颜色都只是基线，站长可全部改写或删掉重来。 */
    private const LEVELS = [
        [1, '初来乍到', 0, '#9aa4b2'],
        [2, '常驻读者', 100, '#4a9eff'],
        [3, '活跃作者', 500, '#2fbf71'],
        [4, '资深成员', 2000, '#f0a500'],
        [5, '站点元老', 10000, '#e0413e'],
    ];

    /** 默认经验规则：amount 单次经验，cap 每日上限（0 = 不限）。 */
    private const RULES = [
        ['post.publish', 50, 200, '发布文章'],
        ['comment.create', 5, 50, '发表评论'],
        ['profile.complete', 10, 10, '完善个人资料'],
    ];

    public function __construct(private DatabaseInterface $db) {}

    public function role(): string { return 'pdo_level_store'; }

    public function seed(): void
    {
        $pdo  = $this->db->pdo();
        $lv   = $pdo->prepare(
            'INSERT IGNORE INTO ' . $this->table('member_levels') . ' (level,name,min_xp,color) VALUES (?,?,?,?)'
        );
        foreach (self::LEVELS as [$level, $name, $minXp, $color]) {
            $lv->execute([$level, $name, $minXp, $color]);
        }
        $rule = $pdo->prepare(
            'INSERT IGNORE INTO ' . $this->table('member_xp_rules')
            . ' (action,amount,daily_cap,enabled,description) VALUES (?,?,?,1,?)'
        );
        foreach (self::RULES as [$action, $amount, $cap, $desc]) {
            $rule->execute([$action, $amount, $cap, $desc]);
        }
    }

    public function levels(): array
    {
        return $this->db->query(
            'SELECT level,name,min_xp,color FROM ' . $this->table('member_levels') . ' ORDER BY min_xp ASC, level ASC'
        );
    }

    public function upsertLevel(int $level, string $name, int $minXp, string $color): bool
    {
        if ($level < 1 || $minXp < 0 || trim($name) === '') {
            throw new InvalidArgumentException('等级需满足：level ≥ 1、min_xp ≥ 0、name 非空');
        }
        // 先显式检查门槛唯一性：ON DUPLICATE KEY 会命中「另一个等级的门槛」并改坏那一行，
        // 这是唯一索引与主键不同键时的真实陷阱（16d 用例覆盖）。
        $dup = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM ' . $this->table('member_levels') . ' WHERE min_xp = ? AND level <> ?'
        );
        $dup->execute([$minXp, $level]);
        if ((int) $dup->fetchColumn() > 0) {
            throw new InvalidArgumentException('等级门槛重复：min_xp=' . $minXp);
        }
        try {
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO ' . $this->table('member_levels') . ' (level,name,min_xp,color) VALUES (?,?,?,?)'
                . ' ON DUPLICATE KEY UPDATE name = VALUES(name), min_xp = VALUES(min_xp), color = VALUES(color)'
            );
            $stmt->execute([$level, trim($name), $minXp, $color]);
        } catch (PDOException $e) {
            // 两个等级门槛相同会让「按经验查等级」出现二义，写入时即拒绝，不留到决策时才发现。
            if ((string) $e->getCode() === '23000') {
                throw new InvalidArgumentException('等级门槛重复：min_xp=' . $minXp);
            }
            throw $e;
        }
        return true;
    }

    public function deleteLevel(int $level): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM ' . $this->table('member_levels') . ' WHERE level = ?');
        $stmt->execute([$level]);
        return $stmt->rowCount() > 0;
    }

    public function rule(string $action): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT action,amount,daily_cap,enabled,description FROM ' . $this->table('member_xp_rules')
            . ' WHERE action = ?'
        );
        $stmt->execute([$action]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function rules(): array
    {
        return $this->db->query(
            'SELECT action,amount,daily_cap,enabled,description FROM ' . $this->table('member_xp_rules')
            . ' ORDER BY action ASC'
        );
    }

    public function upsertRule(string $action, int $amount, int $dailyCap, bool $enabled, string $description): bool
    {
        if ($action === '' || $amount <= 0 || $dailyCap < 0) {
            throw new InvalidArgumentException('经验规则需满足：action 非空、amount > 0、daily_cap ≥ 0');
        }
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO ' . $this->table('member_xp_rules')
            . ' (action,amount,daily_cap,enabled,description) VALUES (?,?,?,?,?)'
            . ' ON DUPLICATE KEY UPDATE amount = VALUES(amount), daily_cap = VALUES(daily_cap),'
            . ' enabled = VALUES(enabled), description = VALUES(description)'
        );
        $stmt->execute([$action, $amount, $dailyCap, $enabled ? 1 : 0, $description]);
        return true;
    }

    public function deleteRule(string $action): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM ' . $this->table('member_xp_rules') . ' WHERE action = ?');
        $stmt->execute([$action]);
        return $stmt->rowCount() > 0;
    }

    private function table(string $name): string { return $this->db->prefix() . $name; }
}
