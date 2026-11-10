<?php
declare(strict_types=1);

use Wx\Core\Http\Request;
use Wx\Systems\Comment\PdoCommentStore;
use Wx\Systems\Content\PdoContentRepository;
use Wx\Systems\Data\PdoDatabase;
use Wx\Tools\Scale\Probe;
use Wx\Tools\Scale\Seeder;

/**
 * 33 · 规模验证 —— 在千级真实数据上确认「读路径的假设」成立。
 *
 * v0.24 以前所有证据都在几十行夹具上跑，「用了 keyset 所以分页快」「建了索引所以走索引」
 * 都只是读代码得出的推断。第一次上量就查出两件事：
 *   - 带 keyword 的搜索必抛 PDOException（同一命名占位符用了两次）→ 500，功能完全不可用；
 *   - 热文评论全量读，5 万条要 90 MB，一篇热文能把站点打到 OOM。
 *
 * 这里守三件事：搜索不抛异常（回归）、分页与评论走索引、读取上限生效且可被调用方感知。
 * 不断言绝对耗时 —— 它随机器与负载波动，拿它当阈值只会得到假红或假绿。
 */
echo "\n=== 33. 规模验证：千级数据下的索引、分页与读取上限 ===\n";

require_once $root . '/tools/scale/Seeder.php';
require_once $root . '/tools/scale/Probe.php';

check('出厂：规模工具与协作者在位', function () use ($root): void {
    truthy(is_file($root . '/tools/scale.php'), '缺少 tools/scale.php');
    foreach (['Seeder', 'Probe'] as $c) {
        truthy(is_file($root . '/tools/scale/' . $c . '.php'), '缺少协作者 ' . $c);
    }

    $cli = (string) file_get_contents($root . '/tools/scale.php');
    truthy(str_contains($cli, "'run'"), 'CLI 缺少 run 模式');
    truthy(str_contains($cli, 'finally'), 'CLI 未在 finally 中清理临时表');

    $doc = (string) file_get_contents($root . '/docs/PERFORMANCE.md');
    truthy(str_contains($doc, 'php tools/scale.php run'), '性能文档未指向可重跑的规模验证命令');
    truthy(str_contains($doc, 'truncated'), '性能文档未说明评论截断的语义');
});

check('实测：搜索可用、索引命中、分页与评论读取有上限', function (): void {
    $db = new PdoDatabase('mysql:host=127.0.0.1;dbname=wx_blog;charset=utf8mb4', 'wx', 'wx_pass_2026', 'wx_scale_');
    $posts = new PdoContentRepository($db);
    $comments = new PdoCommentStore($db);
    $seeder = new Seeder($db, $posts, $comments);

    try {
        $seeder->reset();
        $seeder->posts(2000);
        $seeder->comments(6000, 'post-000001', 3000, 2000);
        $probe = new Probe($db, $posts, $comments);

        // 回归：v0.25 之前这一句必抛「Invalid parameter number」—— 占位符 :kw 用了两次。
        $miss = $probe->search('zzz-不存在的关键词');
        truthy($miss['items'] === 0, '搜索返回了不存在的关键词结果');
        $hit = $probe->search('正文内容');
        truthy($hit['items'] > 0, '搜索命中全部却返回空，过滤条件写反了');

        $first = $probe->page(10);
        $deep = $probe->page(10, 1800);
        truthy($first['items'] === 10 && $deep['items'] === 10, '分页条数不符');
        truthy($deep['ms'] < max(20.0, $first['ms'] * 5),
            sprintf('深翻页比首页慢太多（%.2f / %.2f ms），keyset 可能退化为 OFFSET', $deep['ms'], $first['ms']));

        $capped = $probe->commentsFor('post-000001', 1000);
        truthy($capped['rows'] === 1000, 'forPost 上限未生效：期望 1000 行，实际 ' . $capped['rows']);

        foreach ($probe->explain(1800) as $row) {
            if ($row['sql'] === '搜索') continue; // LIKE '%…%' 必然全表扫描，已在 PERFORMANCE 说明
            truthy($row['type'] !== 'ALL', $row['sql'] . ' 走全表扫描（type=ALL）');
            truthy($row['key'] !== '-', $row['sql'] . ' 未命中任何索引');
        }

        printf("         首页 %.2f ms · 深翻 %.2f ms · 搜索(无命中) %.2f ms · 评论 limit=1000 → %d 行\n",
            $first['ms'], $deep['ms'], $miss['ms'], $capped['rows']);
    } finally {
        $comments->dropSchema();
        $posts->dropSchema();
    }
});

check('接口层：超过 read_limit 时显式声明 truncated，不静默丢数据', function (): void {
    $kernel = kernel(null);
    $kernel->boot();   // 先装配再取契约：handle() 才会自动 boot，直接 resolve 拿不到
    $store = $kernel->container()->resolve(\Wx\Contracts\CommentStoreInterface::class);
    // 随机 slug：内存实现是静态数组，固定 slug 会让第二次运行累积成 4200 条，total 断言假失败。
    $slug = 'bulk-' . bin2hex(random_bytes(4));
    for ($i = 0; $i < 2100; $i++) {
        $store->add(['post_slug' => $slug, 'body' => '条目 ' . $i, 'status' => 'published']);
    }

    $r = $kernel->handle(new Request('GET', '/api/v1/comments', ['post_slug' => $slug]));
    $data = (array) (json_decode($r->body(), true)['data'] ?? []);

    truthy(count((array) ($data['items'] ?? [])) === 2000, '截断条数应等于 read_limit 默认值 2000');
    truthy(($data['truncated'] ?? null) === true, '截断未声明：客户端会以为评论只有这么多');
    truthy((int) ($data['total'] ?? 0) === 2100, 'total 应仍是真实总数，不受截断影响');
});
