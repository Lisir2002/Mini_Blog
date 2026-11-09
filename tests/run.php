<?php
declare(strict_types=1);

/**
 * 第一性原理守卫测试入口。
 * 运行：php tests/run.php
 *
 * 本文件只做引导与汇总；断言按关注点分装在 tests/groups/*.php，
 * 共享辅助在 tests/helpers.php。单个文件不会同时承担多个职权域。
 */
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures.php';
require __DIR__ . '/fixtures-release.php';
require_once dirname(__DIR__) . '/tools/audit/Policy.php';
require_once dirname(__DIR__) . '/tools/audit/CollaborationGraph.php';
require_once dirname(__DIR__) . '/tools/audit/Auditor.php';

// 每次运行都重新审计并刷新报告：第 12 组验证的必须是最新事实，而非过期的文本快照。
\Wx\Tools\Audit\Auditor::write($root, \Wx\Tools\Audit\Auditor::run($root));

foreach (glob(__DIR__ . '/groups/*.php') as $group) {
    require $group;
}

echo "\n=== 结果 ===\n  {$passed} passed, {$failed} failed\n\n";
exit($failed === 0 ? 0 : 1);
