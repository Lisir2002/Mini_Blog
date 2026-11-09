<?php
declare(strict_types=1);
namespace Wx\Systems\Content;

use Wx\Contracts\ContentRepositoryInterface;

/**
 * 无 MySQL 时的内存适配器（优雅降级）。
 * 与 PdoContentRepository 实现同一契约：主题、插件、首页包、核心全部无需改动。
 */
final class MemoryContentRepository implements ContentRepositoryInterface
{
    /** @var list<array<string, mixed>> */
    private static array $rows = [];

    public function __construct()
    {
        if (self::$rows === []) {
            $now = date(DATE_ATOM);
            self::$rows = [
                ['id' => 1, 'slug' => 'first-post', 'title' => '第一篇文章', 'body' => '这是内容系统提供的第一篇示例文章。',
                 'excerpt' => '这是内容系统提供的第一篇示例文章。', 'status' => 'published', 'author' => 'admin',
                 'created_at' => $now, 'updated_at' => $now],
                ['id' => 2, 'slug' => 'second-post', 'title' => '第二篇文章', 'body' => '文章数据来自 ContentRepositoryInterface，而非核心硬编码。',
                 'excerpt' => '文章数据来自 ContentRepositoryInterface，而非核心硬编码。', 'status' => 'published', 'author' => 'admin',
                 'created_at' => $now, 'updated_at' => $now],
            ];
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
                throw new \InvalidArgumentException('非法游标');
            }
            $after = (int) $decoded;
        }
        $rows = array_values(array_filter(
            self::$rows,
            static function (array $row) use ($status, $kw, $after): bool {
                if ($status !== 'any' && $row['status'] !== $status) return false;
                if ($row['id'] <= $after) return false;
                if ($kw !== '' && !str_contains((string) $row['title'] . (string) $row['body'], $kw)) return false;
                return true;
            }
        ));
        $total = count($rows);
        $page = array_slice($rows, 0, $limit);
        $hasMore = $total > $limit;
        return [
            'items'       => $page,
            'next_cursor' => $hasMore && $page !== [] ? base64_encode((string) end($page)['id']) : null,
            'total'       => $this->count($status),
        ];
    }

    public function findBySlug(string $slug): ?array
    {
        foreach (self::$rows as $row) {
            if ($row['slug'] === $slug) return $row;
        }
        return null;
    }

    public function create(array $post): int
    {
        $now = date(DATE_ATOM);
        $id  = count(self::$rows) + 1;
        self::$rows[] = [
            'id' => $id, 'slug' => (string) $post['slug'], 'title' => (string) $post['title'],
            'body' => (string) $post['body'], 'excerpt' => (string) ($post['excerpt'] ?? ''),
            'status' => (string) ($post['status'] ?? 'published'), 'author' => (string) ($post['author'] ?? ''),
            'created_at' => $now, 'updated_at' => $now,
        ];
        return $id;
    }

    public function update(string $slug, array $fields): bool
    {
        foreach (self::$rows as $index => $row) {
            if ($row['slug'] !== $slug) continue;
            $changed = false;
            foreach (['title', 'body', 'status'] as $key) {
                if (!array_key_exists($key, $fields)) continue;
                self::$rows[$index][$key] = (string) $fields[$key];
                if ($key === 'body') self::$rows[$index]['excerpt'] = (string) $fields[$key];
                $changed = true;
            }
            if ($changed) self::$rows[$index]['updated_at'] = date(DATE_ATOM);
            return $changed;
        }
        return false;
    }

    public function count(string $status = 'published'): int
    {
        if ($status === 'any') return count(self::$rows);
        return count(array_filter(self::$rows, static fn(array $r): bool => $r['status'] === $status));
    }
}
