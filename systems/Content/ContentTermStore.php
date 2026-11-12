<?php
declare(strict_types=1);
namespace Wx\Systems\Content;

use InvalidArgumentException;
use PDOException;
use Wx\Contracts\DatabaseInterface;
use Wx\Core\Kernel;
use Wx\Systems\Data\Db;
use Wx\Systems\Data\Utf8Text;

/**
 * 分类与标签的统一存储 —— 一张术语表 + 一张关系表，用 kind 区分两种术语。
 *
 * 两种术语的读写路径完全相同：都是 slug / 名称 / 多对多绑定。拆成两套实现
 * 会得到两份几乎逐字相同的 SQL，将来修一个缺陷要记得改两处 —— 那是缺陷的温床。
 *
 * 「绑定」只接受已存在的术语。自动创建看起来更方便，实际是把拼写错误变成新标签：
 * 打错一个字母就在站点上多出一个词条，而且没有任何地方会报错。
 */
final class ContentTermStore
{
    public const KINDS = ['category', 'tag'];

    public function __construct(private DatabaseInterface $db) {}

    /**
     * 从内核取连接。没有 data 层（拔库降级）时返回 null ——
     * 分类标签必须有持久化载体，内存里凭空造一套只会让人以为数据存下来了。
     */
    public static function fromKernel(Kernel $kernel): ?self
    {
        $db = Db::optional($kernel);
        return $db === null ? null : new self($db);
    }

    public function ensureSchema(): void
    {
        \Wx\Systems\Data\Schema::apply($this->db, __DIR__ . '/migrations');
    }

    /** @return list<array<string, mixed>> 含每个术语的文章数（不含回收站文章） */
    public function list(string $kind = 'any'): array
    {
        $sql = 'SELECT t.id,t.kind,t.slug,t.name,t.description,t.created_at,t.updated_at,'
             . ' (SELECT COUNT(*) FROM ' . $this->relTable() . ' r JOIN ' . $this->postTable() . ' p ON p.id = r.post_id'
             . '   WHERE r.term_id = t.id AND p.deleted_at IS NULL) AS post_count'
             . ' FROM ' . $this->table() . ' t'
             . ($kind !== 'any' ? ' WHERE t.kind = :kind' : '')
             . ' ORDER BY t.kind ASC, t.name ASC';
        return array_map([$this, 'normalize'], $this->db->query($sql, $kind !== 'any' ? [':kind' => $kind] : []));
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $rows = $this->db->query(
            'SELECT id,kind,slug,name,description,created_at,updated_at FROM ' . $this->table() . ' WHERE id = ? LIMIT 1',
            [$id]
        );
        return $rows === [] ? null : $this->normalize($rows[0]);
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $kind, string $slug): ?array
    {
        $rows = $this->db->query(
            'SELECT id,kind,slug,name,description,created_at,updated_at FROM ' . $this->table()
            . ' WHERE kind = ? AND slug = ? LIMIT 1',
            [$kind, $slug]
        );
        return $rows === [] ? null : $this->normalize($rows[0]);
    }

    /** 创建术语。同 kind 下 slug 冲突是客户端可修正的状态，抛 InvalidArgumentException 由上层译成 409。 */
    public function create(string $kind, string $slug, string $name, string $description = ''): int
    {
        $this->assertShape($kind, $slug, $name);
        if ($this->findBySlug($kind, $slug) !== null) {
            throw new InvalidArgumentException('术语已存在：' . $kind . ':' . $slug);
        }
        $now = date('Y-m-d H:i:s');
        try {
            $this->db->exec(
                'INSERT INTO ' . $this->table() . ' (kind,slug,name,description,created_at,updated_at)'
                . ' VALUES (?,?,?,?,?,?)',
                [$kind, $slug, $name, $description, $now, $now]
            );
        } catch (PDOException $e) {
            // 并发创建同一 slug：预检通过但写入撞唯一索引，仍然要译成「已存在」而不是 500。
            throw new InvalidArgumentException('术语已存在：' . $kind . ':' . $slug);
        }
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string, mixed> $fields 允许 name / description；kind 与 slug 不可改，改 slug 会让旧链接全断 */
    public function update(int $id, array $fields): bool
    {
        $sets = [];
        $args = [];
        if (array_key_exists('name', $fields)) {
            $name = trim((string) $fields['name']);
            $this->assertName($name);
            $sets[] = 'name = ?';
            $args[] = $name;
        }
        if (array_key_exists('description', $fields)) {
            $sets[] = 'description = ?';
            $args[] = trim((string) $fields['description']);
        }
        if ($sets === []) return false;

        // updated_at 原先无条件刷新：同值提交只要跨秒就被 MySQL 算成「已改变」，
        // 接口于是误报 updated=true。幂等不该取决于两次请求隔几秒 —— 先比对，未变则不发 UPDATE。
        $current = $this->find($id);
        if ($current === null) return false;
        $nextName = array_key_exists('name', $fields) ? trim((string) $fields['name']) : (string) $current['name'];
        $nextDesc = array_key_exists('description', $fields) ? trim((string) $fields['description']) : (string) $current['description'];
        if ($nextName === (string) $current['name'] && $nextDesc === (string) $current['description']) return false;

        $sets[] = 'updated_at = ?';
        $args[] = date('Y-m-d H:i:s');
        $args[] = $id;
        return $this->db->exec('UPDATE ' . $this->table() . ' SET ' . implode(', ', $sets) . ' WHERE id = ?', $args) > 0;
    }

    /** 删除术语并清理其绑定关系，返回被清理的关系条数。两步必须在同一事务里。 */
    public function delete(int $id): int
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $removed = $this->db->exec('DELETE FROM ' . $this->relTable() . ' WHERE term_id = ?', [$id]);
            $this->db->exec('DELETE FROM ' . $this->table() . ' WHERE id = ?', [$id]);
            $pdo->commit();
            return $removed;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * 把文章的绑定整体替换为 $termIds。
     * 返回 ['added' => n, 'removed' => n]，让调用方能区分「真的变了吗」与「本来就这样」。
     * @param list<int> $termIds
     * @return array{added: int, removed: int}
     */
    public function sync(int $postId, array $termIds): array
    {
        $termIds = array_values(array_unique(array_map('intval', $termIds)));
        // forPost 返回的是术语本身（id 即 term_id），不能去读一个不存在的 term_id 键：
        // 那会让「当前绑定」恒为空，于是每次同步都在重复插入，第二次就撞主键。
        $current = array_map(static fn(array $r): int => (int) $r['id'], $this->forPost($postId));
        $added   = array_values(array_diff($termIds, $current));
        $removed = array_values(array_diff($current, $termIds));

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            if ($removed !== []) {
                $this->db->exec(
                    'DELETE FROM ' . $this->relTable() . ' WHERE post_id = ? AND term_id IN ('
                    . implode(',', array_fill(0, count($removed), '?')) . ')',
                    array_merge([$postId], $removed)
                );
            }
            $now = date('Y-m-d H:i:s');
            foreach ($added as $termId) {
                $this->db->exec(
                    'INSERT INTO ' . $this->relTable() . ' (post_id,term_id,created_at) VALUES (?,?,?)',
                    [$postId, $termId, $now]
                );
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['added' => count($added), 'removed' => count($removed)];
    }

    /** @return list<array<string, mixed>> 文章绑定的术语，按分类优先排序 */
    public function forPost(int $postId): array
    {
        return array_map([$this, 'normalize'], $this->db->query(
            'SELECT t.id,t.kind,t.slug,t.name,t.description,t.created_at,t.updated_at'
            . ' FROM ' . $this->relTable() . ' r JOIN ' . $this->table() . ' t ON t.id = r.term_id'
            . ' WHERE r.post_id = ? ORDER BY t.kind ASC, t.name ASC',
            [$postId]
        ));
    }

    /** @return list<array<string, mixed>> 批量读取，避免列表页每篇一次查询 */
    public function forPosts(array $postIds): array
    {
        if ($postIds === []) return [];
        $ids = implode(',', array_fill(0, count($postIds), '?'));
        $rows = $this->db->query(
            'SELECT r.post_id, t.id,t.kind,t.slug,t.name FROM ' . $this->relTable() . ' r'
            . ' JOIN ' . $this->table() . ' t ON t.id = r.term_id'
            . ' WHERE r.post_id IN (' . $ids . ') ORDER BY t.kind ASC, t.name ASC',
            array_values($postIds)
        );
        $map = [];
        foreach ($rows as $row) {
            $postId = (int) $row['post_id'];
            unset($row['post_id']);
            $map[$postId][] = $this->normalize($row);
        }
        return $map;
    }

    private function assertShape(string $kind, string $slug, string $name): void
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('kind 只允许 category / tag');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,190}$/', $slug)) {
            throw new InvalidArgumentException('术语 slug 只允许小写字母、数字与连字符');
        }
        $this->assertName($name);
    }

    /** 按字符计数，与显示口径一致：8 个汉字不该被算成 24 个字。 */
    private function assertName(string $name): void
    {
        $name = trim($name);
        if ($name === '') throw new InvalidArgumentException('术语名称不能为空');
        if (!Utf8Text::valid($name)) throw new InvalidArgumentException('术语名称不是合法的 UTF-8');
        if (Utf8Text::len($name) > 60) throw new InvalidArgumentException('术语名称不能超过 60 字');
    }

    private function normalize(array $row): array
    {
        $row['id'] = (int) $row['id'];
        if (isset($row['post_count'])) $row['post_count'] = (int) $row['post_count'];
        return $row;
    }

    private function table(): string { return $this->db->prefix() . 'content_terms'; }

    private function relTable(): string { return $this->db->prefix() . 'content_term_relations'; }

    private function postTable(): string { return $this->db->prefix() . 'content_posts'; }
}
