<?php
declare(strict_types=1);

/**
 * 规模验证 CLI —— 在万级真实数据上确认分页是 O(1)、索引真的被用上、热文读取有上限。
 *
 * 运行：php tools/scale.php run [--posts=10000] [--comments=50000] [--hot=3000]
 *       php tools/scale.php drop      只清理临时表
 *
 * 为什么要有这个工具：v0.24 以前所有证据都在几十行夹具上跑，
 * 「用了 keyset 游标所以分页快」「建了索引所以查询走索引」都只是读代码得出的推断。
 * 万级数据下第一次实测就查出两件事 —— 搜索接口只要带 keyword 就抛异常，
 * 以及热文评论全量读出 5 万条要 90 MB。
 *
 * 边界：只写独立前缀的临时表（默认 wx_scale_），结束时强制清理；
 * 不连生产库以外的任何东西，不改真实数据。
 */

use Wx\Systems\Comment\PdoCommentStore;
use Wx\Systems\Content\PdoContentRepository;
use Wx\Systems\Data\PdoDatabase;
use Wx\Tools\Scale\Probe;
use Wx\Tools\Scale\Seeder;

$root = require dirname(__DIR__) . '/core/bootstrap.php';
require_once __DIR__ . '/scale/Seeder.php';
require_once __DIR__ . '/scale/Probe.php';

$command = (string) ($argv[1] ?? 'run');
$config  = require $root . '/config/app.php';
$dbCfg   = (array) ($config['database'] ?? []);
$prefix  = (string) ($dbCfg['prefix'] ?? 'wx_') . 'scale_';

$opts = ['posts' => 10000, 'comments' => 50000, 'hot' => 20000];
foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--(\w+)=(\d+)$/', $arg, $m) === 1) $opts[$m[1]] = (int) $m[2];
}

if (!in_array($command, ['run', 'drop'], true)) {
    fwrite(STDERR, "用法：php tools/scale.php [run|drop] [--posts=N] [--comments=M] [--hot=K]\n");
    exit(2);
}

$db = new PdoDatabase((string) ($dbCfg['dsn'] ?? ''), (string) ($dbCfg['user'] ?? ''),
    (string) ($dbCfg['pass'] ?? ''), $prefix);

try {
    $db->pdo();
} catch (Throwable $e) {
    echo "未连接数据库（{$e->getMessage()}），本次未验证 —— 不是通过。\n";
    exit(0);
}

$posts    = new PdoContentRepository($db);
$comments = new PdoCommentStore($db);
$seeder   = new Seeder($db, $posts, $comments);

if ($command === 'drop') {
    $seeder->reset();
    $comments->dropSchema();
    $posts->dropSchema();
    echo "已清理 {$prefix}* 临时表\n";
    exit(0);
}

$N = max(100, $opts['posts']);
$C = max(100, $opts['comments']);
$H = max(0, min($opts['hot'], $C));

$failures = [];
$ms = static fn(float $v): string => sprintf('%8.2f ms', $v);

echo "=== 规模验证：{$N} 篇文章 / {$C} 条评论（{$H} 条挂在 post-000001） ===\n";
echo "临时表前缀 {$prefix}；测量为单进程、无预热\n\n";

try {
    $seeder->reset();
    $tPosts = $seeder->posts($N);
    $tComments = $seeder->comments($C, 'post-000001', $H, $N);
    printf("造数：文章 %.0f ms，评论 %.0f ms\n\n", $tPosts, $tComments);

    $probe = new Probe($db, $posts, $comments);

    $first = $probe->page(10);
    $deep  = $probe->page(10, (int) ($N * 0.9));
    $cnt   = $probe->count();
    $miss  = $probe->search('zzz-不存在的关键词');
    $hit   = $probe->search('正文内容');

    printf("  %-26s %s   items=%d total=%d\n", '分页 首页', $ms($first['ms']), $first['items'], $first['total']);
    printf("  %-26s %s   items=%d（cursor 指向第 %d 条）\n", '分页 深翻页', $ms($deep['ms']), $deep['items'], (int) ($N * 0.9));
    printf("  %-26s %s   ← list() 每页都会调用\n", 'count() 单独', $ms($cnt));
    printf("  %-26s %s   items=%d\n", '搜索 无命中', $ms($miss['ms']), $miss['items']);
    printf("  %-26s %s   items=%d\n", '搜索 命中全部', $ms($hit['ms']), $hit['items']);

    // 先小后大：反过来的话，全量读分配的内存会被下一次测量复用，差值读成 0。
    $capped = $probe->commentsFor('post-000001', 2000);
    $hotAll = $probe->commentsFor('post-000001');
    printf("  %-26s %s   rows=%d  增量内存 %.1f MB\n", '热文 全量读', $ms($hotAll['ms']), $hotAll['rows'], $hotAll['mb']);
    printf("  %-26s %s   rows=%d  增量内存 %.1f MB\n", '热文 limit=2000', $ms($capped['ms']), $capped['rows'], $capped['mb']);

    echo "\n=== 索引命中（EXPLAIN） ===\n";
    $explain = $probe->explain((int) ($N * 0.9));
    foreach ($explain as $row) {
        printf("  %-6s type=%-8s key=%-16s rows=%-8s extra=%s\n",
            $row['sql'], $row['type'], $row['key'], $row['rows'], $row['extra']);
    }

    // —— 判定：只断言与机制有关、换机器不会翻转的部分，耗时阈值一律不设 ——
    $ratio = $first['ms'] > 0 ? $deep['ms'] / $first['ms'] : 0.0;
    if ($ratio > 3.0) {
        $failures[] = sprintf('深翻页比首页慢 %.1f 倍（%.2f / %.2f ms），keyset 未生效', $ratio, $deep['ms'], $first['ms']);
    }
    if ($deep['items'] === 0) {
        $failures[] = '深翻页返回空，游标没有取到数据';
    }
    foreach ($explain as $row) {
        if ($row['sql'] === '搜索') continue; // LIKE '%…%' 必然全表扫描，见文档
        if ($row['type'] === 'ALL') $failures[] = $row['sql'] . ' 走全表扫描（type=ALL）';
        if ($row['key'] === '-')    $failures[] = $row['sql'] . ' 未命中任何索引';
    }
    if ($capped['rows'] !== min(2000, $H)) {
        $failures[] = sprintf('forPost 上限未生效：期望 %d 行，实际 %d', min(2000, $H), $capped['rows']);
    }
    if ($miss['items'] !== 0) {
        $failures[] = '无命中搜索返回了结果，关键词过滤失效';
    }

    echo "\n=== 判定 ===\n";
    foreach ($failures as $f) echo "  FAIL  {$f}\n";
    echo $failures === []
        ? "  PASS  分页 O(1)（深翻页 / 首页 = " . number_format($ratio, 2) . "）、索引命中、热文上限生效\n"
        : "  共 " . count($failures) . " 项不成立\n";
} finally {
    $comments->dropSchema();
    $posts->dropSchema();
    echo "\n已清理 {$prefix}* 临时表\n";
}

exit($failures === [] ? 0 : 1);
