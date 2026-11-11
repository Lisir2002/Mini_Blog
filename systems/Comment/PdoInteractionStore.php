<?php
declare(strict_types=1);
namespace Wx\Systems\Comment;

use Wx\Contracts\DatabaseInterface;

/**
 * 互动台账的 MySQL 实现。
 *
 * 幂等靠联合主键 + INSERT IGNORE / DELETE 的受影响行数，不靠「先查再插」：
 * 两个并发请求同时查到「没点过」时，唯一键保证只有一条能落库。
 * 批量计数固定三条 SQL（votes / reports / 当前主体），与评论条数 N 无关 ——
 * 列表页 read_limit 默认 2000，逐条查台账会退化成 2000 次往返。
 */
final class PdoInteractionStore implements CommentInteractionStoreInterface
{
    public function __construct(private DatabaseInterface $db) {}

    public function vote(int $commentId, string $actorKey, bool $add): array
    {
        if ($add) {
            $stmt = $this->db->pdo()->prepare(
                'INSERT IGNORE INTO ' . $this->votes() . ' (comment_id, voter_key, created_at) VALUES (?, ?, ?)'
            );
            $stmt->execute([$commentId, $actorKey, date('Y-m-d H:i:s')]);
        } else {
            $stmt = $this->db->pdo()->prepare(
                'DELETE FROM ' . $this->votes() . ' WHERE comment_id = ? AND voter_key = ?'
            );
            $stmt->execute([$commentId, $actorKey]);
        }
        $changed = $stmt->rowCount() > 0;
        $likes = $this->likeCount($commentId);
        return ['changed' => $changed, 'likes' => $likes, 'liked' => $add ? true : false];
    }

    public function report(int $commentId, string $actorKey, string $reason): array
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT IGNORE INTO ' . $this->reports()
            . ' (comment_id, reporter_key, reason, created_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$commentId, $actorKey, $reason, date('Y-m-d H:i:s')]);
        return [
            'changed'  => $stmt->rowCount() > 0,
            'reports'  => $this->reportCount($commentId),
            'reported' => true,
        ];
    }

    public function countsFor(array $commentIds, string $actorKey): array
    {
        $out = [];
        foreach ($commentIds as $id) {
            $out[$id] = ['likes' => 0, 'reports' => 0, 'liked' => false, 'reported' => false];
        }
        $ids = array_values(array_unique(array_map('intval', $commentIds)));
        if ($ids === []) return $out;

        $ph = implode(',', array_fill(0, count($ids), '?'));
        foreach ([
            'likes'    => ['table' => $this->votes(),   'column' => 'voter_key'],
            'reports'  => ['table' => $this->reports(), 'column' => 'reporter_key'],
        ] as $field => $spec) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT comment_id, COUNT(*) AS c FROM ' . $spec['table']
                . ' WHERE comment_id IN (' . $ph . ') GROUP BY comment_id'
            );
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $row) {
                $id = (int) $row['comment_id'];
                if (isset($out[$id])) $out[$id][$field] = (int) $row['c'];
            }

            $mine = $this->db->pdo()->prepare(
                'SELECT comment_id FROM ' . $spec['table']
                . ' WHERE ' . $spec['column'] . ' = ? AND comment_id IN (' . $ph . ')'
            );
            $mine->execute(array_merge([$actorKey], $ids));
            $flag = $field === 'likes' ? 'liked' : 'reported';
            foreach ($mine->fetchAll() as $row) {
                $id = (int) $row['comment_id'];
                if (isset($out[$id])) $out[$id][$flag] = true;
            }
        }
        return $out;
    }

    public function reportCount(int $commentId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM ' . $this->reports() . ' WHERE comment_id = ?'
        );
        $stmt->execute([$commentId]);
        return (int) $stmt->fetchColumn();
    }

    public function edit(int $commentId, string $body, int $expectedEditCount): array
    {
        // SET 里含单调递增的 edit_count，值必然改变 —— 因此 rowCount()=0 只可能是
        // WHERE 未命中（版本已被别人推进），不存在「值没变」的歧义。
        // 这与 v0.28 文章状态迁移不同：那次写的是同值，才必须回读确认。
        $stmt = $this->db->pdo()->prepare(
            'UPDATE ' . $this->comments()
            . ' SET body = ?, edited_at = ?, edit_count = edit_count + 1'
            . ' WHERE id = ? AND edit_count = ? AND status <> ?'
        );
        $stmt->execute([$body, date('Y-m-d H:i:s'), $commentId, $expectedEditCount, CommentPolicy::DELETED]);
        $changed = $stmt->rowCount() > 0;
        $current = $this->currentEditCount($commentId);
        return ['changed' => $changed, 'edit_count' => $current ?? $expectedEditCount];
    }

    public function stats(int $commentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT edit_count, edited_at FROM ' . $this->comments() . ' WHERE id = ?'
        );
        $stmt->execute([$commentId]);
        $row = $stmt->fetch();
        return [
            'likes'      => $this->likeCount($commentId),
            'reports'    => $this->reportCount($commentId),
            'edit_count' => is_array($row) ? (int) $row['edit_count'] : 0,
            'edited_at'  => is_array($row) && $row['edited_at'] !== null ? (string) $row['edited_at'] : null,
        ];
    }

    public function queue(int $limit, int $offset): array
    {
        $sql = $this->queueSql('SELECT c.id AS comment_id, COALESCE(r.cnt, 0) AS reports, COALESCE(l.cnt, 0) AS likes')
            . ' ORDER BY reports DESC, c.id ASC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
        $rows = $this->db->query($sql);
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'comment_id' => (int) $row['comment_id'],
                'reports'    => (int) $row['reports'],
                'likes'      => (int) $row['likes'],
            ];
        }
        return $out;
    }

    public function queueTotal(): int
    {
        $rows = $this->db->query(
            'SELECT COUNT(*) AS c FROM (' . $this->queueSql('SELECT c.id') . ') q'
        );
        return (int) ($rows[0]['c'] ?? 0);
    }

    /** 队列口径：待审 + 有举报，排除已删除；两个 LEFT JOIN 都是聚合后的派生表，行数不随评论数膨胀。 */
    private function queueSql(string $select): string
    {
        return $select . ' FROM ' . $this->comments() . ' c'
            . ' LEFT JOIN (SELECT comment_id, COUNT(*) AS cnt FROM ' . $this->reports() . ' GROUP BY comment_id) r'
            . ' ON r.comment_id = c.id'
            . ' LEFT JOIN (SELECT comment_id, COUNT(*) AS cnt FROM ' . $this->votes() . ' GROUP BY comment_id) l'
            . ' ON l.comment_id = c.id'
            . ' WHERE c.status <> ' . $this->db->pdo()->quote(CommentPolicy::DELETED)
            . ' AND (c.status = ' . $this->db->pdo()->quote(CommentPolicy::PENDING) . ' OR r.cnt > 0)';
    }

    private function likeCount(int $commentId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM ' . $this->votes() . ' WHERE comment_id = ?'
        );
        $stmt->execute([$commentId]);
        return (int) $stmt->fetchColumn();
    }

    private function currentEditCount(int $commentId): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT edit_count FROM ' . $this->comments() . ' WHERE id = ?'
        );
        $stmt->execute([$commentId]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    private function comments(): string { return $this->db->prefix() . 'comments'; }
    private function votes(): string    { return $this->db->prefix() . 'comment_votes'; }
    private function reports(): string  { return $this->db->prefix() . 'comment_reports'; }
}
