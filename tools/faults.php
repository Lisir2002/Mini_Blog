<?php
declare(strict_types=1);

/**
 * 故障旅程 CLI —— 在装好的隔离站点上逐条注入故障，验证「坏的时候不骗人」。
 *
 *   php tools/faults.php        跑完整故障旅程，输出纯文本证据
 *   php tools/faults.php json   以 JSON 输出结果（供守卫 / 外部工具消费）
 *
 * 覆盖：数据库不可达与恢复 → 令牌过期与恢复 → 同键进行中 → 失败释放占位
 *       → 上传越界 → 主题不可解析 → 装配期 / 构造期故障（真实 SAPI）
 *       → 工作区零残留复核。
 *
 * 与 tools/journey.php 的分工：那条证明「顺利时走得通」，这条证明「出错时说得清」。
 * 两者共用 tools/journey/ 的隔离站点与 HTTP 探针，不复制第二套基础设施。
 *
 * 约束：启动期故障走 PHP 内置服务器（真实 SAPI），其余走 Kernel::handle() 真实请求管线；
 *       只在 /tmp 副本 + 独立表前缀落地；
 * 结论一律是可重跑文本，不用截图；退出码 0 = 全部故障都被正确识别。
 */

use Wx\Tools\Faults\FaultJourney;

$root = require dirname(__DIR__) . '/core/bootstrap.php';
// 复用成功路径的隔离站点 / 探针 / 安装阶段。
foreach (['Site', 'Probe', 'Server', 'JourneyState', 'InstallStage', 'ContentStage', 'ThemeStage', 'Journey'] as $class) {
    require_once __DIR__ . '/journey/' . $class . '.php';
}
foreach (['FaultJourney', 'DataFaults', 'AuthFaults', 'RequestFaults', 'StartupFaults'] as $class) {
    require_once __DIR__ . '/faults/' . $class . '.php';
}

$mode = (string) ($argv[1] ?? 'text');
if (!in_array($mode, ['text', 'json'], true)) {
    fwrite(STDERR, "用法：php tools/faults.php [text|json]\n");
    exit(2);
}

$result = FaultJourney::run($root);

if ($mode === 'json') {
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    exit($result['failed'] === 0 ? 0 : 1);
}

echo "=== 故障旅程（隔离站点 / 真实请求管线 / 故障注入） ===\n";
foreach ($result['lines'] as $line) echo $line . "\n";
echo "\n=== 结果 ===\n";
printf("  %d passed, %d failed\n", $result['passed'], $result['failed']);
echo '  工作区零残留：' . ($result['residue'] === [] ? '是' : '否（' . implode(', ', $result['residue']) . '）') . "\n";
echo '  隔离站点清理：' . ($result['facts']['cleanup'] ?? '?') . "\n";
exit($result['failed'] === 0 ? 0 : 1);
