<?php
declare(strict_types=1);

/**
 * 职责审计器 CLI 入口 —— 规矩 H / I 的可重跑文本证据。
 * 运行：php tools/audit-responsibility.php
 * 产物：docs/responsibility-report.json（机器可读）
 * 退出码：0 = 无违规且无孤儿协作；1 = 存在违规。
 *
 * 计算逻辑在 tools/audit/Auditor.php，守卫测试调用同一个引擎，不会读到过期报告。
 */
require_once __DIR__ . '/audit/Policy.php';
require_once __DIR__ . '/audit/CollaborationGraph.php';
require_once __DIR__ . '/audit/Auditor.php';

use Wx\Tools\Audit\Auditor;

$root = dirname(__DIR__);
$report = Auditor::run($root);
Auditor::write($root, $report);
$violations = $report['violations'];
$orphans = $report['collaboration']['orphans'];

$heaviest = $report['files'];
usort($heaviest, static fn(array $a, array $b): int => $b['sloc'] <=> $a['sloc']);

echo "=== 单文件职责审计 ===\n";
echo '扫描 ' . $report['summary']['php_files'] . ' 个 PHP 文件，共 ' . $report['summary']['total_sloc'] . " 有效代码行\n";
echo "体积前 5：\n";
foreach (array_slice($heaviest, 0, 5) as $m) {
    printf("  %4d 行 | %2d 主体 | %2d 公开方法 | 扇出 %2d | 跨度 %d (%s) | %s\n",
        $m['sloc'], $m['classes'], $m['public_methods'], $m['fan_out'], $m['concern_span'],
        implode('+', $m['concerns']) ?: '-', $m['path']);
}
echo "\n=== 违规 ===\n";
if ($violations === []) {
    echo "  无\n";
} else {
    foreach ($violations as $v) echo "  [{$v['rule']}] {$v['path']}：{$v['detail']}\n";
}
echo "\n=== 协作图 ===\n";
$s = $report['collaboration']['summary'];
echo "  契约 {$s['contracts']} 个 | use 边 {$s['use_edges']} 条 | 事件生产者 {$s['event_producers']} / 消费者 {$s['event_consumers']}\n";
echo "  清单 operation {$s['operations']} 个，已绑定执行者 {$s['operations_bound']} 个\n";
if ($orphans === []) {
    echo "  孤儿协作：无\n";
} else {
    foreach ($orphans as $o) echo "  [{$o['type']}] {$o['detail']}\n";
}
echo "\n报告：docs/responsibility-report.json\n";
exit(($violations === [] && $orphans === []) ? 0 : 1);
