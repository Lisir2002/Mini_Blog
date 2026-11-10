<?php
declare(strict_types=1);

use Wx\Systems\Data\Db;
use Wx\Systems\Data\IntegrityCheckerInterface;
use Wx\Systems\Data\PdoDatabase;
use Wx\Systems\Data\QueryProfilerInterface;
use Wx\Systems\Data\RelationRegistry;
use Wx\Systems\Data\Schema;

/**
 * 21d · 数据完整性 —— 孤儿巡检、关系登记校验、结构指纹闸门。
 *
 * 「不加外键」是有意的解耦，但它必须有人接住：这张网就是巡检 + 本组守卫。
 */
echo "\n=== 21d. 完整性：孤儿巡检 / 关系登记 / 结构指纹 ===\n";

$site   = tempSite('data-integrity');
$prefix = 'di_';
dropTables($prefix);
$k = isolatedKernel($site, $prefix);
$k->boot();
$connected = Db::connected($k);

check('关系登记：只接受表名形状，拒绝 SQL 片段', function (): void {
    $relations = new RelationRegistry();
    $relations->declare('demo.parent', 'items', 'parent_id', 'items', 'id', true);
    truthy($relations->count() === 1, '登记未生效');

    $rejected = 0;
    foreach (['items; DROP TABLE x', 'items`', '../items'] as $bad) {
        try {
            $relations->declare('bad.rel', $bad, 'parent_id', 'items');
        } catch (InvalidArgumentException $e) {
            $rejected++;
        }
    }
    truthy($rejected === 3, '非法表名应被全部拒绝，实际拒绝 ' . $rejected);
});

check('孤儿巡检：父行不存在时报告出来，且只报告不修复', function () use ($k, $connected, $prefix): void {
    if (!$connected) return;
    $db = $k->container()->resolve(\Wx\Contracts\DatabaseInterface::class);
    $table = $prefix . 'comments';
    $db->exec(
        'INSERT INTO ' . $table . ' (post_slug, parent_id, root_id, path, depth, author, body, status, created_at)'
        . ' VALUES (?, 999999, 0, ?, 1, ?, ?, ?, ?)',
        ['first-post', '/999999/', '孤儿', '故意制造的孤儿行', 'published', date('Y-m-d H:i:s')]
    );
    $report = $k->container()->resolve(IntegrityCheckerInterface::class)->inspect();
    truthy($report['skipped'] === false, '有连接时不应标记为跳过');
    $hit = null;
    foreach ($report['findings'] as $finding) {
        if ($finding['relation'] === 'comment.parent') $hit = $finding;
    }
    truthy($hit !== null && $hit['count'] >= 1, '未报告 comment.parent 的孤儿行');
    // 只报告不修复：行必须还在，否则「事故」被悄悄抹掉了。
    $rows = $db->query('SELECT COUNT(*) AS n FROM ' . $table . ' WHERE parent_id = 999999');
    truthy((int) ($rows[0]['n'] ?? 0) === 1, '巡检不应删除任何行');
    $db->exec('DELETE FROM ' . $table . ' WHERE parent_id = 999999');
});

check('结构指纹：内容没变时，冷连接再启动不再重放建表语句', function (): void {
    // 用独立前缀与两条全新连接验证闸门本身：不依赖前面哪个组先碰过哪张表，
    // 否则「指纹失效」与「测试顺序变化」会长成同一个失败。
    $prefix = 'dfp_' . bin2hex(random_bytes(3)) . '_';
    $cfg = require dirname(__DIR__, 2) . '/config/app.php';
    $dir = dirname(__DIR__, 2) . '/systems/Comment/migrations';
    try {
        $first = new \Wx\Systems\Data\QueryProfiler();
        $db1 = new PdoDatabase(
            (string) $cfg['database']['dsn'], (string) $cfg['database']['user'],
            (string) $cfg['database']['pass'], $prefix, $first
        );
        Schema::apply($db1, $dir);
        truthy($first->count() > 2, '首次应用应真实执行迁移，实际查询数 ' . $first->count());

        // 冷连接：进程内 memo 对它无效，只能靠库里的指纹判断。
        $second = new \Wx\Systems\Data\QueryProfiler();
        $db2 = new PdoDatabase(
            (string) $cfg['database']['dsn'], (string) $cfg['database']['user'],
            (string) $cfg['database']['pass'], $prefix, $second
        );
        Schema::apply($db2, $dir);
        truthy($second->count() <= 2, '冷连接重放了建表语句，查询数 ' . $second->count());
    } finally {
        dropTables($prefix);
    }
});
