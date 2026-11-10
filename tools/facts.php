<?php
declare(strict_types=1);

/**
 * 计数唯一真源 CLI —— 结构性事实从仓库物理状态计算，文档与守卫锁逐项对账。
 *
 *   php tools/facts.php            输出当前事实集（人读）
 *   php tools/facts.php verify     对账文档锚点 + 守卫硬编码锁，退出码 0 = 零漂移
 *   php tools/facts.php json       输出事实集 JSON（供生成器 / 外部工具消费）
 *
 * 存在意义：项目里的 69 接口 / 46 权限点 / 24 核心文件 / 13 契约 / 17 系统层
 * 曾散落在十余个文档与守卫里，每次升级都要手工批量同步，且必然漏（本次审计
 * 就查出 README 同时写着 67 接口与 69 接口、282 守卫与 292 passed）。
 * 现在事实只算一次，其余位置都是「待对账对象」。
 *
 * 边界：不写文件、不改文档、不引导内核、不连库。修不修由人决定，
 * 但「哪里没同步」由这条命令和 26b 守卫明确报出。
 */

use Wx\Tools\Facts\CodeTable;
use Wx\Tools\Facts\Facts;
use Wx\Tools\Facts\Reconciler;

$root = require dirname(__DIR__) . '/core/bootstrap.php';
require_once __DIR__ . '/facts/Facts.php';
require_once __DIR__ . '/facts/Reconciler.php';
require_once __DIR__ . '/facts/CodeTable.php';

$mode = (string) ($argv[1] ?? 'text');
if (!in_array($mode, ['text', 'json', 'verify'], true)) {
    fwrite(STDERR, "用法：php tools/facts.php [text|json|verify]\n");
    exit(2);
}

$facts = Facts::compute($root);

if ($mode === 'json') {
    echo json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

if ($mode === 'text') {
    echo "=== 当前事实集（从仓库物理状态计算） ===\n";
    foreach (Facts::table($facts) as $line) echo $line . "\n";
    echo "\n说明：php_files / total_sloc 为运行快照，来自 docs/responsibility-report.json\n";
    echo "（由 php tests/run.php 每次刷新）。运行 php tools/facts.php verify 对账文档与守卫锁。\n";
    exit(0);
}

$check = Reconciler::run($root, $facts);
$locks = Reconciler::guardLocks($root, $facts);
$codes = CodeTable::run($root);
echo "=== 计数对账（文档锚点 {$check['checked']} 项 + 守卫锁 + 错误码表 {$codes['checked']} 项） ===\n";
foreach ($check['findings'] as $f) echo '  FAIL  ' . $f . "\n";
foreach ($locks as $l) echo '  FAIL  ' . $l . "\n";
foreach ($codes['findings'] as $f) echo '  FAIL  ' . $f . "\n";
$n = count($check['findings']) + count($locks) + count($codes['findings']);
echo $n === 0
    ? "结果：零漂移（{$check['checked']} 项文档数字 + " . count($locks) . " 项守卫锁 + "
        . "{$codes['checked']} 个错误码均与事实一致）\n"
    : "结果：{$n} 处漂移，请同步后重跑\n";
exit($n === 0 ? 0 : 1);
