<?php
declare(strict_types=1);

use Wx\Tools\Journey\Journey;

/**
 * 26a · 全链路旅程守卫 —— 断言「端到端旅程真的能跑通并且不留痕」。
 *
 * 与 tools/journey.php 共用同一个 Journey::run() 执行器：守卫不做静态检查、
 * 不另写一遍旅程，跑的是完全相同的请求序列。这兑现「每规矩配可执行守卫」，
 * 也避免两份实现悄悄分叉。
 */
echo "\n=== 26a. 全链路旅程：可重跑 / 零残留 / 不越界 ===\n";

foreach (['Site', 'Probe', 'JourneyState', 'InstallStage', 'ContentStage', 'ThemeStage', 'Journey'] as $c) {
    require_once $root . '/tools/journey/' . $c . '.php';
}

check('旅程工具出厂：CLI 与七个协作者齐备，支持 text / json 两种输出', function () use ($root): void {
    truthy(is_file($root . '/tools/journey.php'), '缺少 tools/journey.php');
    foreach (['Site', 'Probe', 'JourneyState', 'InstallStage', 'ContentStage', 'ThemeStage', 'Journey'] as $c) {
        truthy(is_file($root . '/tools/journey/' . $c . '.php'), '缺少协作者 ' . $c);
    }
    $cli = (string) file_get_contents($root . '/tools/journey.php');
    truthy(str_contains($cli, "'text', 'json'"), 'CLI 未提供 text / json 双模式');
    truthy(str_contains($cli, 'kernel::handle') === false, 'CLI 不应绕过请求管线');
});

check('隔离设计：站点复制、独立前缀、finally 清理、零残留复核都在代码里', function () use ($root): void {
    $site = (string) file_get_contents($root . '/tools/journey/Site.php');
    truthy(str_contains($site, 'sys_get_temp_dir()'), '未在临时目录建站');
    truthy(str_contains($site, "'wxj_' . bin2hex(random_bytes(3))"), '未使用随机独立表前缀');
    truthy(str_contains($site, 'function residue(') && str_contains($site, 'function cleanup('),
        '缺少零残留复核或清理');
    $journey = (string) file_get_contents($root . '/tools/journey/Journey.php');
    truthy(str_contains($journey, '} finally {') && str_contains($journey, 'cleanup()'),
        '清理未放在 finally 里');
});

/** 跑一次真实旅程，返回结构化结果。 */
$runJourney = static fn(): array => Journey::run($root);

check('真实执行：22 步全绿，覆盖安装→内容→附件→表情→主题→停用全链路', function () use ($runJourney): void {
    $r = $runJourney();
    truthy($r['failed'] === 0, '旅程存在失败步骤：' . implode(' | ', array_filter(
        $r['lines'], static fn(string $l): bool => str_starts_with(ltrim($l), 'FAIL'))));
    truthy($r['passed'] >= 22, '旅程步骤数不足：' . $r['passed']);
    $text = implode("\n", $r['lines']);
    foreach (['五步安装', '真实身份', '评论树', '附件', '自定义表情', '前台', '后台', '停用回 Headless'] as $stage) {
        truthy(str_contains($text, $stage), '旅程缺少阶段：' . $stage);
    }
    truthy($r['residue'] === [], '工作区出现残留：' . implode(', ', $r['residue']));
    truthy(($r['facts']['cleanup'] ?? '') === 'ok', '隔离站点未被清理');
    $GLOBALS['wx_journey_first'] = ['passed' => $r['passed'], 'failed' => $r['failed']];
});

check('连续重跑结果一致：随机目录 / 独立前缀不互相干扰', function () use ($runJourney): void {
    $first = $GLOBALS['wx_journey_first'] ?? null;
    truthy($first !== null, '前置旅程未完成');
    $r = $runJourney();
    truthy($r['failed'] === 0, '第二次运行出现失败');
    truthy($r['passed'] === $first['passed'], '两次运行步骤数不一致');
    truthy($r['residue'] === [] && ($r['facts']['cleanup'] ?? '') === 'ok', '第二次运行留下残留');
});

check('跑完现场：工作区无 local.php、无旅程表前缀、默认 Headless 不变', function () use ($root): void {
    truthy(!is_file($root . '/config/local.php'), '工作区残留 config/local.php');
    $app = (string) file_get_contents($root . '/config/app.php');
    preg_match("#'theme'\s*=>\s*\[\s*'active'\s*=>\s*([^,]+),#", $app, $m);
    truthy(isset($m[1]) && trim($m[1]) === 'null', '默认 Headless 被改动');
    $cfg = require $root . '/config/app.php';
    $db = (array) ($cfg['database'] ?? []);
    if ((string) ($db['dsn'] ?? '') !== '') {
        try {
            $pdo = new PDO((string) $db['dsn'], (string) ($db['user'] ?? ''), (string) ($db['pass'] ?? ''));
            $left = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES"
                . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'wxj\\_%'")->fetchColumn();
            truthy((int) $left === 0, '数据库残留旅程表：' . (int) $left . ' 张');
        } catch (Throwable) {
            // 数据库不可达时由前一条旅程断言负责失败，这里不重复报错。
        }
    }
});

check('不越界：旅程不新增系统层 / 接口 / 契约，core/ 仍 24 文件', function () use ($root): void {
    truthy(count(glob($root . '/core/*.php') ?: []) + count(glob($root . '/core/*/*.php') ?: []) === 24,
        'core/ 文件数被改动');
    truthy(count(glob($root . '/systems/*/system.json') ?: []) === 18, '系统层数量被改动');
    $reg = json_decode((string) file_get_contents($root . '/api/registry.json'), true);
    truthy(count($reg['endpoints'] ?? []) === 92, '接口清单被改动');
    $perms = 0;
    foreach (glob($root . '/systems/*/permissions.json') ?: [] as $f) {
        $perms += count((array) (json_decode((string) file_get_contents($f), true)['permissions'] ?? []));
    }
    truthy($perms === 49, '权限点数量被改动：' . $perms);
});
