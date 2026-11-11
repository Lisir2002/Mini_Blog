<?php
declare(strict_types=1);
namespace Wx\Systems\Content;

use InvalidArgumentException;
use Wx\Contracts\DatabaseInterface;

/**
 * 文章读取查询 —— 列表、回收站、计数的唯一构造点。
 *
 * 从仓储里分出来的原因不是「文件太长」，而是读写混在一起时最容易被改坏：
 * 过滤条件散在写入路径旁边，新增一个状态就只记得改一半。
 * 这里只有 SELECT，改读口径不会碰到事务与 UPSERT。
 */
final class PostQuery
{
    private const COLUMNS = 'id,slug,title,body,excerpt,status,author,created_at,updated_at,deleted_at,publish_at,pinned,sort_weight';

    public function __construct(private DatabaseInterface $db) {}

    /**
     * 列表与回收站共用查询体。$onlyDeleted 决定看「活着的」还是「回收站」，
     * 两种视图的过滤条件互斥，因此不存在「忘记排除已删除」这条路径。
     * @param array<string, mixed> $query
     */
    public function page(array $query, bool $onlyDeleted): array
    {
        $limit  = max(1, min(100, (int) ($query['limit'] ?? 10)));
        $status = (string) ($query['status'] ?? 'published');
        $kw     = trim((string) ($query['keyword'] ?? ''));
        $term   = trim((string) ($query['term'] ?? ''));
        $kind   = trim((string) ($query['kind'] ?? ''));
        $pinned = (bool) ($query['pinned'] ?? false);
        $after  = self::cursor((string) ($query['cursor'] ?? ''));

        $where  = [$onlyDeleted ? 'deleted_at IS NOT NULL' : 'deleted_at IS NULL'];
        $params = [];
        if (!$onlyDeleted) {
            if ($status !== 'any') { $where[] = 'status = :status'; $params[':status'] = $status; }
            // 定时发布不能靠定时任务：没有 cron 的站点也得在到点那一刻自动可见，
            // 唯一可靠的判定点是读路径。反过来，未到点的文章不能算进 published 计数。
            if ($status === 'published') {
                $where[] = '(publish_at IS NULL OR publish_at <= :now)';
                $params[':now'] = date('Y-m-d H:i:s');
            }
        }
        if ($pinned) $where[] = 'pinned = 1';
        if ($kw !== '') {
            // 两个独立占位符，不能复用 :kw —— 连接是 ATTR_EMULATE_PREPARES=false（原生预处理），
            // MySQL 协议不支持同一个命名参数出现两次，复用会在 execute 抛
            // 「Invalid parameter number」。搜索从上线起就是 500，而内存实现用的是 str_contains，
            // 行为不一致，所以两组测试都没发现。
            // 用户输入里的 % 与 _ 是普通字符，不是通配符：不转义的话，搜一个 % 会返回全站。
            // 反斜杠先转，否则会把后面补的转义符再转一遍。ESCAPE 显式声明，不依赖
            // 服务端 sql_mode（NO_BACKSLASH_ESCAPES 下默认转义会失效）。
            $like = strtr($kw, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
            $where[] = "(title LIKE :kw_title ESCAPE '\\\\' OR body LIKE :kw_body ESCAPE '\\\\')";
            $params[':kw_title'] = '%' . $like . '%';
            $params[':kw_body']  = '%' . $like . '%';
        }
        if ($term !== '') {
            $where[] = 'id IN (SELECT r.post_id FROM ' . $this->db->prefix() . 'content_term_relations r'
                     . ' JOIN ' . $this->db->prefix() . 'content_terms t ON t.id = r.term_id'
                     . ' WHERE t.slug = :term' . ($kind !== '' ? ' AND t.kind = :kind' : '') . ')';
            $params[':term'] = $term;
            if ($kind !== '') $params[':kind'] = $kind;
        }

        // 游标只影响「这一页从哪开始」，不影响「一共多少」。两套条件分开，
        // 否则按标签筛选后 total 仍是全站已发布数，界面会显示「共 12 篇」却一条也翻不到。
        $pageWhere  = $where;
        $pageParams = $params;
        if ($after > 0 && !$pinned) { $pageWhere[] = 'id > :after'; $pageParams[':after'] = $after; }

        // 置顶是「推荐位」，不混进时间线：keyset 分页的键是 id，若把 pinned 排进主序，
        // 两次翻页之间有人置顶，就会出现「第 2 页重复第 1 页」或整篇被跳过。
        $order = $pinned ? 'pinned DESC, sort_weight DESC, id DESC' : 'id ASC';
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ' . $this->db->prefix() . 'content_posts'
            . ' WHERE ' . implode(' AND ', $pageWhere)
            . ' ORDER BY ' . $order . ' LIMIT ' . ($limit + 1)
        );
        foreach ($pageParams as $key => $value) $stmt->bindValue($key, $value);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);
        $items = array_map([PostRow::class, 'normalize'], $rows);
        return [
            'items'       => $items,
            'next_cursor' => $hasMore && !$pinned && $items !== [] ? base64_encode((string) end($items)['id']) : null,
            'total'       => $this->countWhere($where, $params),
        ];
    }

    /** 与列表同一套过滤条件的计数 —— total 必须与 items 同口径。 */
    private function countWhere(array $where, array $params): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM ' . $this->db->prefix() . 'content_posts WHERE ' . implode(' AND ', $where)
        );
        foreach ($params as $key => $value) $stmt->bindValue($key, $value);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * $status 取值为具体状态 / 'any' / 'trash'：具体状态与 any 都不含回收站，
     * published 额外排除未到点的定时文章 —— 存量口径必须与列表一致。
     */
    public function count(string $status = 'published'): int
    {
        $params = [];
        $where  = ['deleted_at IS NULL'];
        if ($status === 'trash') {
            $where = ['deleted_at IS NOT NULL'];
        } elseif ($status !== 'any') {
            $where[] = 'status = :status';
            $params[':status'] = $status;
            if ($status === 'published') {
                $where[] = '(publish_at IS NULL OR publish_at <= :now)';
                $params[':now'] = date('Y-m-d H:i:s');
            }
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM ' . $this->db->prefix() . 'content_posts WHERE ' . implode(' AND ', $where)
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** 游标是 base64 后的 id。非法游标必须报错：静默从头开始会让「翻页」变成「永远第一页」。 */
    private static function cursor(string $cursor): int
    {
        if ($cursor === '') return 0;
        $decoded = base64_decode($cursor, true);
        if ($decoded === false || !ctype_digit($decoded)) {
            throw new InvalidArgumentException('非法游标');
        }
        return (int) $decoded;
    }
}
