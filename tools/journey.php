<?php
declare(strict_types=1);

/**
 * 全链路旅程 CLI —— 从零安装到停用回 Headless 的一次真实用户旅程。
 *
 *   php tools/journey.php        跑完整旅程，输出纯文本证据
 *   php tools/journey.php json   以 JSON 输出结果（供守卫 / 外部工具消费）
 *
 * 覆盖：全新站点基线 → 五步安装 → 真实令牌 → 文章 / 表情 token → 评论树
 *       → 附件上传下载 → 自定义表情 → 前台门户 → 后台主题分区 → 停用回 Headless
 *       → 工作区零残留复核。
 *
 * 约束（与项目第一性原理一致）：
 *   1. 全程走 Kernel::handle(Request) 真实请求管线，不直接调容器方法；
 *   2. 只在 /tmp 隔离站点 + 独立表前缀落地，结束删表删目录；
 *   3. 结论一律是可重跑文本，不用截图；退出码 0 = 全绿。
 *
 * 为什么放在 tools/ 而不是 tests/groups/：这是一条「能独立跑、能给人看」的
 * 运维/验收命令，守卫只负责断言它真的跑通（26a），不重复实现一遍旅程。
 */

use Wx\Tools\Journey\Journey;

$root = require dirname(__DIR__) . '/core/bootstrap.php';
foreach (['Site', 'Probe', 'JourneyState', 'InstallStage', 'ContentStage', 'ThemeStage', 'Journey'] as $class) {
    require_once __DIR__ . '/journey/' . $class . '.php';
}

$mode = (string) ($argv[1] ?? 'text');
if (!in_array($mode, ['text', 'json'], true)) {
    fwrite(STDERR, "用法：php tools/journey.php [text|json]\n");
    exit(2);
}

$result = Journey::run($root);

if ($mode === 'json') {
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    exit($result['failed'] === 0 ? 0 : 1);
}

echo "=== 全链路旅程（隔离站点 / 真实 HTTP 管线 / 纯文本证据） ===\n";
foreach ($result['lines'] as $line) echo $line . "\n";
echo "\n=== 结果 ===\n";
printf("  %d passed, %d failed\n", $result['passed'], $result['failed']);
echo '  工作区零残留：' . ($result['residue'] === [] ? '是' : '否（' . implode(', ', $result['residue']) . '）') . "\n";
echo '  隔离站点清理：' . ($result['facts']['cleanup'] ?? '?') . "\n";
exit($result['failed'] === 0 ? 0 : 1);
