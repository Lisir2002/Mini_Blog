<?php
declare(strict_types=1);
namespace Wx\Systems\Content;

use InvalidArgumentException;
use PDOException;
use Wx\Contracts\ContentRepositoryInterface;
use Wx\Contracts\DatabaseInterface;

/**
 * 内容系统的 MySQL / PDO 实现。
 * 表结构与 SQL 全部在本系统层目录内；核心只认识 ContentRepositoryInterface。
 *
 * 读查询在 PostQuery（只 SELECT），这里只留写入与单行读取：
 * 读写混在一处时，加一个状态最容易只改一半。v0.28 的三条约定：
 *   1. 出站列显式列出，`SELECT *` 会让「加一列」直接变成「改一次接口形状」；
 *   2. 回收站与「未到点的定时发布」都在 SQL 里过滤，不靠调用方记得；
 *   3. 状态迁移用 `WHERE status = :from` 的比较并设置，并发下最多一个成功。
 */
final class PdoContentRepository implements ContentRepositoryInterface
{
    private const COLUMNS = 'id,slug,title,body,excerpt,status,author,created_at,updated_at,deleted_at,publish_at,pinned,sort_weight';

    private ?PostQuery $query = null;

    public function __construct(private DatabaseInterface $db) {}

    /** 幂等建表：装载本系统层 migrations/*.sql，占位前缀替换为实际配置值。 */
    public function ensureSchema(): void
    {
        \Wx\Systems\Data\Schema::apply($this->db, __DIR__ . '/migrations');
    }

    /** 空库时写入两篇示例文章，保证首页开箱有内容；已有数据则不触碰。 */
    public function seed(): void
    {
        if ($this->count('any') > 0) return;
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO ' . $this->table() . ' (slug,title,body,excerpt,status,author,created_at,updated_at)
             VALUES (:slug,:title,:body,:excerpt,:status,:author,:created_at,:updated_at)'
        );
        foreach ([
            ['first-post', '第一篇文章', '这是内容系统提供的第一篇示例文章。'],
            ['second-post', '第二篇文章', '文章数据来自 ContentRepositoryInterface，而非核心硬编码。'],
        ] as [$slug, $title, $body]) {
            $stmt->execute([
                ':slug' => $slug, ':title' => $title, ':body' => $body, ':excerpt' => $body,
                ':status' => 'published', ':author' => 'admin', ':created_at' => $now, ':updated_at' => $now,
            ]);
        }
    }

    public function list(array $query = []): array { return $this->query()->page($query, false); }

    public function trash(array $query = []): array { return $this->query()->page($query, true); }

    public function count(string $status = 'published'): int { return $this->query()->count($status); }

    public function findBySlug(string $slug, bool $withDeleted = false): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ' . $this->table()
            . ' WHERE slug = :slug' . ($withDeleted ? '' : ' AND deleted_at IS NULL') . ' LIMIT 1'
        );
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch();
        return $row === false ? null : PostRow::normalize($row);
    }

    public function create(array $post): int
    {
        $title  = trim((string) ($post['title'] ?? ''));
        $body   = (string) ($post['body'] ?? '');
        $slug   = trim((string) ($post['slug'] ?? ''));
        if ($title === '' || $body === '' || $slug === '') {
            throw new InvalidArgumentException('slug / title / body 均为必填');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,190}$/', $slug)) {
            throw new InvalidArgumentException('slug 只允许小写字母、数字与连字符');
        }
        $status = (string) ($post['status'] ?? 'draft');
        ContentStatus::assertKnown($status);
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO ' . $this->table() . ' (slug,title,body,excerpt,status,author,created_at,updated_at)
             VALUES (:slug,:title,:body,:excerpt,:status,:author,:created_at,:updated_at)'
        );
        try {
            $stmt->execute([
                ':slug' => $slug, ':title' => $title, ':body' => $body,
                ':excerpt' => PostRow::excerpt($body), ':status' => $status,
                ':author' => (string) ($post['author'] ?? ''),
                ':created_at' => $now, ':updated_at' => $now,
            ]);
        } catch (PDOException $e) {
            throw new InvalidArgumentException('slug 已存在或写入失败：' . $slug);
        }
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * 按 slug 更新白名单字段。字段级权限的裁决在上层（ContentSystem），
     * 这里只做存储层校验：字段白名单、状态取值、非空约束。
     * 状态字段走 ContentStatus 矩阵：拼错的取值是参数错误，不可达的迁移是状态冲突。
     * @param array<string, mixed> $fields
     */
    public function update(string $slug, array $fields): bool
    {
        $sets   = [];
        $params = [':slug' => $slug, ':updated_at' => date('Y-m-d H:i:s')];
        foreach ($fields as $key => $value) {
            if ($key === 'status') {
                ContentStatus::assertKnown((string) $value);
                $from = (string) ($this->findBySlug($slug, true)['status'] ?? '');
                if (!ContentStatus::canTransition($from, (string) $value)) {
                    throw new InvalidArgumentException('不可达的状态迁移：' . $from . ' → ' . $value);
                }
            }
            if (!in_array($key, ['title', 'body', 'status', 'pinned', 'weight'], true)) continue;
            if (($key === 'title' || $key === 'body') && trim((string) $value) === '') {
                throw new InvalidArgumentException($key . ' 不能为空');
            }
            if ($key === 'pinned') {
                $sets[] = 'pinned = :pinned';
                $params[':pinned'] = (int) ((bool) $value);
                continue;
            }
            if ($key === 'weight') {
                $sets[] = 'sort_weight = :weight';
                $params[':weight'] = max(-1000, min(1000, (int) $value));
                continue;
            }
            $sets[] = $key . ' = :' . $key;
            $params[':' . $key] = (string) $value;
            if ($key === 'body') {
                $sets[] = 'excerpt = :excerpt';
                $params[':excerpt'] = PostRow::excerpt((string) $value);
            }
        }
        if ($sets === []) return false;

        $stmt = $this->db->pdo()->prepare(
            'UPDATE ' . $this->table() . ' SET ' . implode(', ', $sets)
            . ', updated_at = :updated_at WHERE slug = :slug AND deleted_at IS NULL'
        );
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    public function transition(string $slug, string $from, string $to, ?string $publishAt = null): bool
    {
        ContentStatus::assertKnown($to);
        $params = [':to' => $to, ':updated_at' => date('Y-m-d H:i:s'), ':slug' => $slug, ':from' => $from];
        $sql = 'UPDATE ' . $this->table() . ' SET status = :to, updated_at = :updated_at, publish_at = '
             . ($publishAt === null ? 'NULL' : ':publish_at')
             . ' WHERE slug = :slug AND status = :from AND deleted_at IS NULL';
        if ($publishAt !== null) $params[':publish_at'] = $publishAt;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        // rowCount 为 0 有两种可能：并发者已经把它改成了同一个目标状态（幂等重放，应成功），
        // 或者状态被改到了别处（应冲突）。MySQL 在「值没变」时也返回 0，
        // 所以不能用 rowCount 判断成功与否，只能回读一次确认。
        if ($stmt->rowCount() > 0) return true;
        return (string) ($this->findBySlug($slug)['status'] ?? '') === $to;
    }

    public function delete(string $slug): bool
    {
        // 两个独立占位符：原生预处理不接受同一个命名参数出现两次（HY093）。
        $stmt = $this->db->pdo()->prepare(
            'UPDATE ' . $this->table() . ' SET deleted_at = :now, updated_at = :touched'
            . ' WHERE slug = :slug AND deleted_at IS NULL'
        );
        $now = date('Y-m-d H:i:s');
        $stmt->execute([':now' => $now, ':touched' => $now, ':slug' => $slug]);
        return $stmt->rowCount() > 0;
    }

    public function restore(string $slug): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE ' . $this->table() . ' SET deleted_at = NULL, updated_at = :now'
            . ' WHERE slug = :slug AND deleted_at IS NOT NULL'
        );
        $stmt->execute([':now' => date('Y-m-d H:i:s'), ':slug' => $slug]);
        return $stmt->rowCount() > 0;
    }

    public function purge(string $slug): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM ' . $this->table() . ' WHERE slug = :slug AND deleted_at IS NOT NULL'
        );
        $stmt->execute([':slug' => $slug]);
        return $stmt->rowCount() > 0;
    }

    /** 卸载时清理本系统层自建的表（数据归属清晰，核心不参与）。 */
    public function dropSchema(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec('DROP TABLE IF EXISTS ' . $this->db->prefix() . 'content_term_relations');
        $pdo->exec('DROP TABLE IF EXISTS ' . $this->db->prefix() . 'content_terms');
        $pdo->exec('DROP TABLE IF EXISTS ' . $this->table());
    }

    private function query(): PostQuery { return $this->query ??= new PostQuery($this->db); }

    private function table(): string { return $this->db->prefix() . 'content_posts'; }
}
