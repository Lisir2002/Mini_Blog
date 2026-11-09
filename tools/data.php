<?php
declare(strict_types=1);

/**
 * 数据运维 CLI —— 只读巡检与体量统计。
 *
 * 运行：php tools/data.php verify     校验孤儿行（子行指向不存在的父行）
 *       php tools/data.php stats      列出各表行数
 * 退出码：0 = 无问题或未连库（跳过）；1 = 发现孤儿。
 *
 * 为什么不做 export / import：导出是全库泄露面，备份交给 mysqldump 更可靠。
 * 这里只做「不需要写权限、不会丢数据」的那部分。
 */

use Wx\Systems\Data\Db;
use Wx\Systems\Data\IntegrityCheckerInterface;

$root = require dirname(__DIR__) . '/core/bootstrap.php';
$kernel = new \Wx\Core\Kernel($root);
$kernel->boot();

$command = (string) ($argv[1] ?? 'verify');
$container = $kernel->container();

if ($command === 'stats') {
    if (!Db::connected($kernel)) {
        echo "未连接数据库（database.dsn 为空或不可达），跳过。\n";
        exit(0);
    }
    $db = $container->resolve(\Wx\Contracts\DatabaseInterface::class);
    $rows = $db->query(
        'SELECT table_name AS t, table_rows AS n FROM information_schema.TABLES'
        . ' WHERE table_schema = DATABASE() AND table_name LIKE ? ORDER BY table_name',
        [$db->prefix() . '%']
    );
    echo "=== 表体量（估算值） ===\n";
    foreach ($rows as $row) {
        printf("  %-32s %8d\n", (string) $row['t'], (int) $row['n']);
    }
    echo '合计 ' . count($rows) . " 张表\n";
    exit(0);
}

if ($command !== 'verify') {
    fwrite(STDERR, "用法：php tools/data.php [verify|stats]\n");
    exit(2);
}

$report = $container->resolve(IntegrityCheckerInterface::class)->inspect();
echo "=== 完整性巡检 ===\n";
if ($report['skipped']) {
    echo "未连接数据库或关系未登记，本次未验证（不是通过）。\n";
    exit(0);
}
echo '检查 ' . $report['checked'] . " 条关系\n";
if ($report['ok']) {
    echo "未发现孤儿行。\n";
    exit(0);
}
foreach ($report['findings'] as $finding) {
    printf("  [%s] %s 有 %d 行指向不存在的父行，样例 id：%s\n",
        (string) $finding['relation'],
        (string) $finding['table'],
        (int) $finding['count'],
        implode(', ', array_map('strval', $finding['samples']))
    );
}
echo "共 " . count($report['findings']) . " 条关系存在孤儿；本工具只报告，不自动修复。\n";
exit(1);
