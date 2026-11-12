<?php
declare(strict_types=1);
namespace Wx\Systems\Content;

use InvalidArgumentException;
use Wx\Contracts\ContentRepositoryInterface;

/**
 * 无 MySQL 时的内存适配器（优雅降级）。
 * 与 PdoContentRepository 实现同一契约：主题、插件、首页包、核心全部无需改动。
 *
 * 降级不降语义：回收站、定时发布、置顶、状态机在这里同样成立 ——
 * 否则「拔掉数据库」会变成「悄悄换了套规则」，而使用者看不出来。
 * 只有分类 / 标签做不到：那要两张表，内存实现没有持久化载体，相关接口直接报不可用。
 */
final class MemoryContentRepository implements ContentRepositoryInterface
{
    /** @var list<array<string, mixed>> */
    private static array $rows = [];

    public function __construct()
    {
        if (self::$rows === []) {
            $now = date(DATE_ATOM);
            foreach ([
                ['first-post', '第一篇文章', '这是内容系统提供的第一篇示例文章。'],
                ['second-post', '第二篇文章', '文章数据来自 ContentRepositoryInterface，而非核心硬编码。'],
            ] as $index => [$slug, $title, $body]) {
                self::$rows[] = [
                    'id' => $index + 1, 'slug' => $slug, 'title' => $title, 'body' => $body, 'excerpt' => $body,
                    'status' => 'published', 'author' => 'admin', 'created_at' => $now, 'updated_at' => $now,
                    'deleted_at' => null, 'publish_at' => null, 'pinned' => false, 'sort_weight' => 0,
                ];
            }
        }
    }

    public function list(array $query = []): array { return $this->page($query, false); }

    public function trash(array $query = []): array { return $this->page($query, true); }

    /** @param array<string, mixed> $query */
    private function page(array $query, bool $onlyDeleted): array
    {
        $limit  = max(1, min(100, (int) ($query['limit'] ?? 10)));
        $status = (string) ($query['status'] ?? 'published');
        $kw     = trim((string) ($query['keyword'] ?? ''));
        $pinned = (bool) ($query['pinned'] ?? false);
        $after  = 0;
        $cursor = (string) ($query['cursor'] ?? '');
        if ($cursor !== '') {
            $decoded = base64_decode($cursor, true);
            if ($decoded === false || !ctype_digit($decoded)) {
                throw new InvalidArgumentException('非法游标');
            }
            $after = (int) $decoded;
        }
        $now = date(DATE_ATOM);
        $rows = array_values(array_filter(
            self::$rows,
            static function (array $row) use ($status, $kw, $after, $pinned, $onlyDeleted, $now): bool {
                if ($onlyDeleted !== ($row['deleted_at'] !== null)) return false;
                if (!$onlyDeleted) {
                    if ($status !== 'any' && $row['status'] !== $status) return false;
                    if ($status === 'published' && $row['publish_at'] !== null && $row['publish_at'] > $now) return false;
                }
                if ($pinned && !$row['pinned']) return false;
                if (!$pinned && $row['id'] <= $after) return false;
                if ($kw !== '' && !str_contains((string) $row['title'] . (string) $row['body'], $kw)) return false;
                return true;
            }
        ));
        usort($rows, static fn(array $a, array $b): int => $pinned
            ? [$b['sort_weight'], $b['id']] <=> [$a['sort_weight'], $a['id']]
            : $a['id'] <=> $b['id']);
        $total = count($rows);
        $page = array_slice($rows, 0, $limit);
        return [
            'items'       => $page,
            'next_cursor' => $total > $limit && !$pinned && $page !== [] ? base64_encode((string) end($page)['id']) : null,
            'total'       => $this->count($onlyDeleted ? 'trash' : $status),
        ];
    }

    public function findBySlug(string $slug, bool $withDeleted = false): ?array
    {
        foreach (self::$rows as $row) {
            if ($row['slug'] !== $slug) continue;
            if (!$withDeleted && $row['deleted_at'] !== null) return null;
            return $row;
        }
        return null;
    }

    public function create(array $post): int
    {
        ContentStatus::assertKnown((string) ($post['status'] ?? 'draft'));
        $now = date(DATE_ATOM);
        $id  = 0;
        foreach (self::$rows as $row) $id = max($id, (int) $row['id']);
        self::$rows[] = [
            'id' => $id + 1, 'slug' => (string) $post['slug'], 'title' => (string) $post['title'],
            'body' => (string) $post['body'], 'excerpt' => (string) ($post['excerpt'] ?? ''),
            'status' => (string) ($post['status'] ?? 'draft'), 'author' => (string) ($post['author'] ?? ''),
            'created_at' => $now, 'updated_at' => $now,
            'deleted_at' => null, 'publish_at' => null, 'pinned' => false, 'sort_weight' => 0,
        ];
        return $id + 1;
    }

    public function update(string $slug, array $fields): bool
    {
        foreach (self::$rows as $index => $row) {
            if ($row['slug'] !== $slug || $row['deleted_at'] !== null) continue;
            $changed = false;
            foreach (['title', 'body', 'status', 'pinned', 'weight'] as $key) {
                if (!array_key_exists($key, $fields)) continue;
                if ($key === 'status') {
                    ContentStatus::assertKnown((string) $fields[$key]);
                    if (!ContentStatus::canTransition((string) $row['status'], (string) $fields[$key])) {
                        throw new InvalidArgumentException('不可达的状态迁移：' . $row['status'] . ' → ' . $fields[$key]);
                    }
                    if ((string) $fields[$key] !== ContentStatus::PUBLISHED) {
                        self::$rows[$index]['publish_at'] = null;
                    }
                }
                if ($key === 'pinned') {
                    self::$rows[$index]['pinned'] = (bool) $fields[$key];
                } elseif ($key === 'weight') {
                    self::$rows[$index]['sort_weight'] = max(-1000, min(1000, (int) $fields[$key]));
                } else {
                    self::$rows[$index][$key] = (string) $fields[$key];
                    if ($key === 'body') self::$rows[$index]['excerpt'] = (string) $fields[$key];
                }
                $changed = true;
            }
            if ($changed) self::$rows[$index]['updated_at'] = date(DATE_ATOM);
            return $changed;
        }
        return false;
    }

    public function transition(string $slug, string $from, string $to, ?string $publishAt = null): bool
    {
        ContentStatus::assertKnown($to);
        foreach (self::$rows as $index => $row) {
            if ($row['slug'] !== $slug || $row['deleted_at'] !== null) continue;
            if ((string) $row['status'] !== $from) return false;
            self::$rows[$index]['status']     = $to;
            self::$rows[$index]['publish_at'] = $publishAt;
            self::$rows[$index]['updated_at'] = date(DATE_ATOM);
            return true;
        }
        return false;
    }

    public function delete(string $slug): bool
    {
        foreach (self::$rows as $index => $row) {
            if ($row['slug'] !== $slug || $row['deleted_at'] !== null) continue;
            self::$rows[$index]['deleted_at'] = date(DATE_ATOM);
            return true;
        }
        return false;
    }

    public function restore(string $slug): bool
    {
        foreach (self::$rows as $index => $row) {
            if ($row['slug'] !== $slug || $row['deleted_at'] === null) continue;
            self::$rows[$index]['deleted_at'] = null;
            return true;
        }
        return false;
    }

    public function purge(string $slug): bool
    {
        foreach (self::$rows as $index => $row) {
            if ($row['slug'] !== $slug || $row['deleted_at'] === null) continue;
            array_splice(self::$rows, $index, 1);
            return true;
        }
        return false;
    }

    public function count(string $status = 'published'): int
    {
        $now = date(DATE_ATOM);
        return count(array_filter(self::$rows, static function (array $row) use ($status, $now): bool {
            if ($status === 'trash') return $row['deleted_at'] !== null;
            if ($row['deleted_at'] !== null) return false;
            if ($status === 'any') return true;
            if ($row['status'] !== $status) return false;
            return $status !== 'published' || $row['publish_at'] === null || $row['publish_at'] <= $now;
        }));
    }
}
