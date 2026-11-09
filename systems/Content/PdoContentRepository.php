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
 * 列表采用 keyset 游标分页（id > cursor），避免 OFFSET 在深翻页时的性能塌陷。
 */
final class PdoContentRepository implements ContentRepositoryInterface
{
    public function __construct(private DatabaseInterface $db) {}

    /** 幂等建表：装载本系统层 migrations/*.sql，占位前缀替换为实际配置值。 */
    public function ensureSchema(): void
    {
        \Wx\Systems\Database\Schema::apply($this->db, __DIR__ . '/migrations');
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

    public function list(array $query = []): array
    {
        $limit  = max(1, min(100, (int) ($query['limit'] ?? 10)));
        $status = (string) ($query['status'] ?? 'published');
        $kw     = trim((string) ($query['keyword'] ?? ''));
        $after  = 0;
        $cursor = (string) ($query['cursor'] ?? '');
        if ($cursor !== '') {
            $decoded = base64_decode($cursor, true);
            if ($decoded === false || !ctype_digit($decoded)) {
                throw new InvalidArgumentException('非法游标');
            }
            $after = (int) $decoded;
        }

        $where  = [];
        $params = [];
        if ($status !== 'any') { $where[] = 'status = :status'; $params[':status'] = $status; }
        if ($after > 0)       { $where[] = 'id > :after';       $params[':after']  = $after; }
        if ($kw !== '') {
            $where[] = '(title LIKE :kw OR body LIKE :kw)';
            $params[':kw'] = '%' . $kw . '%';
        }
        $sql = 'SELECT id,slug,title,body,excerpt,status,author,created_at,updated_at FROM ' . $this->table()
             . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY id ASC LIMIT ' . ($limit + 1);

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $key => $value) $stmt->bindValue($key, $value);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);
        $items = array_map([$this, 'normalize'], $rows);
        return [
            'items'       => $items,
            'next_cursor' => $hasMore && $items !== [] ? base64_encode((string) end($items)['id']) : null,
            'total'       => $this->count($status),
        ];
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM ' . $this->table() . ' WHERE slug = :slug LIMIT 1');
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->normalize($row);
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
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO ' . $this->table() . ' (slug,title,body,excerpt,status,author,created_at,updated_at)
             VALUES (:slug,:title,:body,:excerpt,:status,:author,:created_at,:updated_at)'
        );
        try {
            $stmt->execute([
                ':slug' => $slug, ':title' => $title, ':body' => $body,
                ':excerpt' => $this->excerpt($body),
                ':status' => (string) ($post['status'] ?? 'published'),
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
     * @param array<string, mixed> $fields
     */
    public function update(string $slug, array $fields): bool
    {
        $sets   = [];
        $params = [':slug' => $slug, ':updated_at' => date('Y-m-d H:i:s')];
        foreach ($fields as $key => $value) {
            if (!in_array($key, ['title', 'body', 'status'], true)) continue;
            $text = (string) $value;
            if (($key === 'title' || $key === 'body') && trim($text) === '') {
                throw new InvalidArgumentException($key . ' 不能为空');
            }
            if ($key === 'status' && !in_array($text, ['published', 'draft', 'private', 'pending'], true)) {
                throw new InvalidArgumentException('非法状态：' . $text);
            }
            $sets[] = $key . ' = :' . $key;
            $params[':' . $key] = $text;
            if ($key === 'body') {
                $sets[] = 'excerpt = :excerpt';
                $params[':excerpt'] = $this->excerpt($text);
            }
        }
        if ($sets === []) return false;

        $stmt = $this->db->pdo()->prepare(
            'UPDATE ' . $this->table() . ' SET ' . implode(', ', $sets)
            . ', updated_at = :updated_at WHERE slug = :slug'
        );
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    public function count(string $status = 'published'): int
    {
        $sql = 'SELECT COUNT(*) FROM ' . $this->table()
             . ($status !== 'any' ? ' WHERE status = :status' : '');
        $stmt = $this->db->pdo()->prepare($sql);
        if ($status !== 'any') $stmt->bindValue(':status', $status);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /** 卸载时清理本系统层自建的表（数据归属清晰，核心不参与）。 */
    public function dropSchema(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS ' . $this->table());
    }

    private function table(): string { return $this->db->prefix() . 'content_posts'; }

    private function normalize(array $row): array
    {
        $row['id']    = (int) $row['id'];
        $row['body']  = (string) $row['body'];
        $row['excerpt'] = (string) ($row['excerpt'] ?? $this->excerpt($row['body']));
        return $row;
    }

    private function excerpt(string $body): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($body)));
        return function_exists('mb_substr') ? mb_substr($text, 0, 120) : substr($text, 0, 120);
    }
}
