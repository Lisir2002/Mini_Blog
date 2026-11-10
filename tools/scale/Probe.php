<?php
declare(strict_types=1);
namespace Wx\Tools\Scale;

use Wx\Contracts\DatabaseInterface;
use Wx\Systems\Comment\PdoCommentStore;
use Wx\Systems\Content\PdoContentRepository;

/**
 * 测量 —— 只读，不改任何数据。
 *
 * 三条口径，避免数字被误读：
 *  1. 每次测量前不预热：这些查询本身足够快，预热只会掩盖首次代价；
 *  2. 分页同时报「单页」与「count()」—— list() 的 total 字段每页都要一次全表 COUNT，
 *     两者混在一起会让人误以为 keyset 很慢（v0.25 实测：12.2 ms 里有 11.8 ms 是 COUNT）；
 *  3. 搜索分别测「无命中」与「命中全部」—— LIKE '%kw%' 无法走索引，无命中必须扫完，
 *     两者的差距就是「最坏情况」的真实倍数。
 */
final class Probe
{
    public function __construct(
        private DatabaseInterface $db,
        private PdoContentRepository $posts,
        private PdoCommentStore $comments
    ) {}

    /** @return array{ms:float, items:int, total:int} */
    public function page(int $limit, ?int $after = null): array
    {
        $q = ['limit' => $limit];
        if ($after !== null) $q['cursor'] = base64_encode((string) $after);
        $t = hrtime(true);
        $r = $this->posts->list($q);
        return [
            'ms'    => (hrtime(true) - $t) / 1e6,
            'items' => count($r['items']),
            'total' => (int) ($r['total'] ?? 0),
        ];
    }

    public function count(): float
    {
        $t = hrtime(true);
        $this->posts->count('published');
        return (hrtime(true) - $t) / 1e6;
    }

    /** @return array{ms:float, items:int} */
    public function search(string $keyword): array
    {
        $t = hrtime(true);
        $r = $this->posts->list(['limit' => 10, 'keyword' => $keyword]);
        return ['ms' => (hrtime(true) - $t) / 1e6, 'items' => count($r['items'])];
    }

    /** @return array{ms:float, rows:int, mb:float} */
    public function commentsFor(string $slug, int $limit = 0): array
    {
        // 不用 memory_get_usage(true)：它以 2 MB 块分配，小样本会读成 0.0 MB。
        // 精确值仍只作参考 —— 真正的判据是 rows（SQL 的 LIMIT 到底有没有生效）。
        $base = memory_get_usage(false);
        $t = hrtime(true);
        $rows = $this->comments->forPost($slug, $limit);
        return [
            'ms'   => (hrtime(true) - $t) / 1e6,
            'rows' => count($rows),
            'mb'   => (memory_get_usage(false) - $base) / 1048576,
        ];
    }

    /**
     * @return list<array{sql:string, type:string, key:string, rows:string, extra:string}>
     * 直接用被测系统层的 SQL 形态，不引入第二套写法 —— 否则 EXPLAIN 的结论就不属于生产查询。
     */
    public function explain(int $after): array
    {
        $p = $this->db->prefix();
        $cases = [
            '分页' => "SELECT id FROM {$p}content_posts WHERE status='published' AND id>{$after} ORDER BY id ASC LIMIT 11",
            '搜索' => "SELECT id FROM {$p}content_posts WHERE status='published' AND (title LIKE '%正文%' OR body LIKE '%正文%') ORDER BY id ASC LIMIT 11",
            '评论' => "SELECT id FROM {$p}comments WHERE post_slug='post-000001' ORDER BY id ASC",
            '计数' => "SELECT COUNT(*) FROM {$p}content_posts WHERE status='published'",
        ];
        $out = [];
        foreach ($cases as $label => $sql) {
            $row = (array) $this->db->pdo()->query('EXPLAIN ' . $sql)->fetch();
            $out[] = [
                'sql'   => $label,
                'type'  => (string) ($row['type'] ?? '?'),
                'key'   => (string) ($row['key'] ?? '-'),
                'rows'  => (string) ($row['rows'] ?? '?'),
                'extra' => (string) ($row['Extra'] ?? ''),
            ];
        }
        return $out;
    }
}
