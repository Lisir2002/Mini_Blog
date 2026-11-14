<?php
declare(strict_types=1);

/**
 * 周期维护运维 CLI —— 统一查看与执行各系统层自报的清理任务。
 *
 *   php tools/maintenance.php list              列出全部任务（id / 间隔 / 可否搭车 / 说明）
 *   php tools/maintenance.php status            只读查看台账（上次运行、成败、累计清理量）
 *   php tools/maintenance.php run [--task=ID] [--force]
 *                                               执行到期任务；--task 只跑一个，--force 忽略间隔
 *   php tools/maintenance.php verify            只读自检：任务声明合法、台账可写、锁可获取
 *
 * 边界：list / status / verify 只读。run 是唯一会真正删除数据的命令，必须显式调用
 * （可配每日定时任务）。默认不搭车、不自动执行，见 config/app.php 的 maintenance 段。
 * 逐任务失败隔离：一个任务抛异常不影响同批其它任务，现场写进台账。
 */

$root = require dirname(__DIR__) . '/core/bootstrap.php';

use Wx\Core\Kernel;
use Wx\Systems\Maintenance\MaintenanceRegistry;

$mode = (string) ($argv[1] ?? 'list');
if (!in_array($mode, ['list', 'status', 'run', 'verify'], true)) {
    fwrite(STDERR, "用法：php tools/maintenance.php [list | status | run [--task=ID] [--force] | verify]\n");
    exit(2);
}

$only  = null;
$force = false;
foreach (array_slice($argv, 2) as $arg) {
    if (str_starts_with($arg, '--task=')) $only = substr($arg, 7);
    elseif ($arg === '--force') $force = true;
    else {
        fwrite(STDERR, "未知参数：{$arg}\n");
        exit(2);
    }
}

$kernel = new Kernel($root);
$kernel->boot();
/** @var MaintenanceRegistry $registry */
$registry = $kernel->container()->resolve(MaintenanceRegistry::class);

if ($mode === 'verify') {
    $v = $registry->verify();
    echo "周期维护自检：{$v['tasks']} 个任务，" . ($v['ok'] ? '全部通过' : '存在问题') . "\n";
    foreach ($v['issues'] as $issue) echo "  - {$issue}\n";
    exit($v['ok'] ? 0 : 1);
}

if ($mode === 'list') {
    echo "周期维护任务清单（" . count($registry->tasks()) . " 项）\n";
    foreach ($registry->tasks() as $task) {
        $d = $task->describe();
        printf("  %-18s 每 %-7s 秒  %-4s  %s\n",
            $d['id'], $d['interval'], $d['light'] ? '搭车' : '仅CLI', $d['description']);
    }
    echo "\n搭车默认关闭；清理须显式 php tools/maintenance.php run\n";
    exit(0);
}

if ($mode === 'status') {
    $ledger = $registry->ledger();
    $rows   = (array) ($ledger['tasks'] ?? []);
    if ($rows === []) {
        echo "台账为空：尚未执行过任何维护任务\n";
        exit(0);
    }
    echo "周期维护台账（更新于 " . date('Y-m-d H:i:s', (int) ($ledger['updated_at'] ?? 0)) . "）\n";
    foreach ($rows as $id => $row) {
        printf("  %-18s %s  第 %d 次  清理 %d  耗时 %sms  %s\n",
            $id, ($row['last_ok'] ?? false) ? '成功' : '失败', (int) ($row['runs'] ?? 0),
            (int) ($row['total_removed'] ?? 0), (string) ($row['last_ms'] ?? '-'),
            (string) ($row['last_error'] ?? $row['note'] ?? ''));
    }
    exit(0);
}

// run：唯一写路径
$out = $registry->run('cli', $only, $force, time());
if ($out['skipped'] !== '') {
    echo "本轮跳过：{$out['skipped']}\n";
    exit(0);
}
echo "周期维护执行结果（{$out['ran']} 项）\n";
$failed = 0;
foreach ($out['results'] as $r) {
    if (!$r['ok']) $failed++;
    printf("  %-18s %s  清理 %-6d  %sms  %s\n",
        $r['id'], $r['ok'] ? '成功' : '失败', $r['removed'], (string) $r['ms'], $r['note']);
}
exit($failed === 0 ? 0 : 1);
