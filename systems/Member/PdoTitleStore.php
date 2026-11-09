<?php
declare(strict_types=1);
namespace Wx\Systems\Member;

use InvalidArgumentException;
use Wx\Contracts\DatabaseInterface;

/**
 * 头衔目录的 MySQL / PDO 实现。
 *
 * 注意这里没有任何 permission_* 表的身影，也没有角色字段：
 * 头衔只描述「这个人被站长认作什么」，不描述「这个人能做什么」。
 */
final class PdoTitleStore implements TitleStoreInterface
{
    /** 默认头衔：founder / contributor 需手动授予，veteran 达到 4 级自动出现。 */
    private const TITLES = [
        ['founder', '站长', '站点创建者', '#e0413e', 100, 1, 0],
        ['contributor', '贡献者', '持续贡献内容或代码', '#2fbf71', 60, 1, 0],
        ['veteran', '资深成员', '等级达到 4 级自动获得', '#f0a500', 50, 1, 4],
    ];

    public function __construct(private DatabaseInterface $db) {}

    public function role(): string { return 'pdo_title_store'; }

    public function seed(): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT IGNORE INTO ' . $this->table('member_titles')
            . ' (title_key,name,description,color,priority,visible,min_level,created_at)'
            . ' VALUES (?,?,?,?,?,?,?,?)'
        );
        $now = date('Y-m-d H:i:s');
        foreach (self::TITLES as [$key, $name, $desc, $color, $priority, $visible, $minLevel]) {
            $stmt->execute([$key, $name, $desc, $color, $priority, $visible, $minLevel, $now]);
        }
    }

    public function titles(bool $includeHidden = true): array
    {
        $sql = 'SELECT title_key,name,description,color,priority,visible,min_level FROM '
            . $this->table('member_titles')
            . ($includeHidden ? '' : ' WHERE visible = 1')
            . ' ORDER BY priority DESC, title_key ASC';
        return array_map([$this, 'cast'], $this->db->query($sql));
    }

    public function upsertTitle(array $title): bool
    {
        $key  = trim((string) ($title['title_key'] ?? ''));
        $name = trim((string) ($title['name'] ?? ''));
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $key) || $name === '') {
            throw new InvalidArgumentException('头衔需满足：title_key 为小写标识、name 非空');
        }
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO ' . $this->table('member_titles')
            . ' (title_key,name,description,color,priority,visible,min_level,created_at)'
            . ' VALUES (?,?,?,?,?,?,?,?)'
            . ' ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description),'
            . ' color = VALUES(color), priority = VALUES(priority), visible = VALUES(visible),'
            . ' min_level = VALUES(min_level)'
        );
        $stmt->execute([
            $key, $name, (string) ($title['description'] ?? ''), (string) ($title['color'] ?? ''),
            (int) ($title['priority'] ?? 0), (int) ($title['visible'] ?? 1) === 1 ? 1 : 0,
            max(0, (int) ($title['min_level'] ?? 0)), date('Y-m-d H:i:s'),
        ]);
        return true;
    }

    public function deleteTitle(string $key): bool
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('DELETE FROM ' . $this->table('member_user_titles') . ' WHERE title_key = ?');
        $stmt->execute([$key]);
        $stmt = $pdo->prepare('DELETE FROM ' . $this->table('member_titles') . ' WHERE title_key = ?');
        $stmt->execute([$key]);
        return $stmt->rowCount() > 0;
    }

    public function grantTitle(string $userId, string $key, string $grantedBy): bool
    {
        if ($userId === '' || $key === '') return false;
        $exists = $this->db->pdo()->prepare(
            'SELECT 1 FROM ' . $this->table('member_titles') . ' WHERE title_key = ?'
        );
        $exists->execute([$key]);
        if ($exists->fetchColumn() === false) {
            throw new InvalidArgumentException('头衔不存在：' . $key);
        }
        $stmt = $this->db->pdo()->prepare(
            'INSERT IGNORE INTO ' . $this->table('member_user_titles')
            . ' (user_id,title_key,granted_by,granted_at) VALUES (?,?,?,?)'
        );
        $stmt->execute([$userId, $key, $grantedBy, date('Y-m-d H:i:s')]);
        return $stmt->rowCount() > 0;
    }

    public function revokeTitle(string $userId, string $key): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM ' . $this->table('member_user_titles') . ' WHERE user_id = ? AND title_key = ?'
        );
        $stmt->execute([$userId, $key]);
        return $stmt->rowCount() > 0;
    }

    public function titlesOf(string $userId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT t.title_key,t.name,t.description,t.color,t.priority,t.visible,t.min_level'
            . ' FROM ' . $this->table('member_user_titles') . ' ut'
            . ' INNER JOIN ' . $this->table('member_titles') . ' t ON t.title_key = ut.title_key'
            . ' WHERE ut.user_id = ? ORDER BY t.priority DESC, t.title_key ASC'
        );
        $stmt->execute([$userId]);
        return array_map([$this, 'cast'], $stmt->fetchAll());
    }

    /** @param array<string, mixed> $row */
    private function cast(array $row): array
    {
        $row['priority']  = (int) $row['priority'];
        $row['visible']   = (bool) $row['visible'];
        $row['min_level'] = (int) $row['min_level'];
        return $row;
    }

    private function table(string $name): string { return $this->db->prefix() . $name; }
}
