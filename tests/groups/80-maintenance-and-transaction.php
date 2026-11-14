<?php
declare(strict_types=1);

/**
 * 80 组 —— v0.66：B1 周期维护系统层 + A2 事务覆盖收口。
 *
 * 只读 / 只写 /tmp：所有会落台账的验证都用临时 root，绝不碰工作区 storage/。
 */

use Wx\Core\Http\Request;
use Wx\Systems\Maintenance\BuiltinTasks;
use Wx\Systems\Maintenance\MaintenanceRegistry;
use Wx\Systems\Maintenance\MaintenanceTask;

/** 临时 root：storage/ + config/ 就够，调度器不读业务表。 */
$tmpRoot = static function (): string {
    $d = sys_get_temp_dir() . '/wx-maint-' . bin2hex(random_bytes(4));
    mkdir($d . '/storage/logs', 0775, true);
    return $d;
};
$wipe = static function (string $d): void {
    if ($d === '' || !is_dir($d)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($d);
};

check('B1·G1 静态：第 19 层清单正确，core/ 仍 24 文件、系统层 19 个', function () use ($root): void {
    $meta = json_decode((string) file_get_contents($root . '/systems/Maintenance/system.json'), true);
    truthy(($meta['name'] ?? '') === 'maintenance', '系统层 name 不对');
    truthy((int) ($meta['priority'] ?? 0) === 90, 'priority 应为 90（排在所有系统层之后）');
    truthy(($meta['requires'] ?? []) === ['log'], '只应声明依赖 log');
    $core = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/core', FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') $core++;
    }
    truthy($core === 24, "core/ PHP 文件数应为 24，实际 {$core}");
    truthy(count(glob($root . '/systems/*/system.json') ?: []) === 19, '系统层应为 19 个');
});

check('B1·G2 真实 Kernel：4 个任务收集齐全，light 标记正确', function (): void {
    $k = kernel(null);
    $k->boot();
    $ids = [];
    foreach ($k->container()->resolve(MaintenanceRegistry::class)->tasks() as $t) {
        $ids[$t->id()] = $t->light();
    }
    foreach (['logs.rotate' => true, 'storage.part' => true,
              'state.sweep' => false, 'metric.prune' => false] as $id => $light) {
        truthy(array_key_exists($id, $ids), "缺少自报任务 {$id}");
        truthy($ids[$id] === $light, "{$id} 的 light 标记不对");
    }
    truthy(count($ids) === 4 && count(array_unique(array_keys($ids))) === 4, '任务 id 应唯一且恰 4 个');
});

check('B1·G3 收集挂在 kernel.ready，且 maintenance.tasks 有真实消费者', function (): void {
    $k = kernel(null);
    $k->boot();
    truthy(in_array('maintenance.tasks', $k->hooks()->tags()['filters'], true), '缺少自报过滤器');
    truthy($k->hooks()->has('kernel.ready'), '收集点 kernel.ready 未订阅');
    truthy($k->hooks()->has('kernel.shutdown'), '搭车点 kernel.shutdown 未订阅');
});

check('B1·G4 默认档位：piggyback=false，处理一次请求不产生台账', function () use ($root): void {
    $cfg = require $root . '/config/app.php';
    truthy(($cfg['maintenance']['piggyback'] ?? null) === false, '搭车默认必须关闭');
    $file = $root . '/storage/maintenance/state.json';
    $before = is_file($file) ? (string) file_get_contents($file) : null;
    (new \Wx\Core\Kernel($root))->handle(new Request('GET', '/__maint_probe__'));
    $after = is_file($file) ? (string) file_get_contents($file) : null;
    truthy($before === $after, '未开启搭车时请求不应触发任何清理或写台账');
});

check('B1·G5 开启搭车：隔离站点里只跑 light，重任务留在 CLI', function () use ($root, $tmpRoot, $wipe): void {
    $dir = tempSite('maint');
    try {
        $k = isolatedKernel($dir, 'wx_maint_', ['maintenance' => ['piggyback' => true]]);
        $k->handle(new Request('GET', '/__maint_probe__'));
        $ledger = json_decode((string) @file_get_contents($dir . '/storage/maintenance/state.json'), true);
        $ran = array_keys((array) ($ledger['tasks'] ?? []));
        truthy(in_array('logs.rotate', $ran, true) && in_array('storage.part', $ran, true), 'light 任务未搭车执行');
        truthy(!in_array('state.sweep', $ran, true) && !in_array('metric.prune', $ran, true),
            '重任务不得在请求里扫表');
    } finally { $wipe($dir); dropTables('wx_maint_'); }
});

check('B1·G6 失败隔离：一个任务抛异常不吞掉同批其它任务', function () use ($tmpRoot, $wipe): void {
    $d = $tmpRoot();
    try {
        $r = new MaintenanceRegistry($d);
        $r->register(new MaintenanceTask('bad', 60, static function (): array {
            throw new RuntimeException('模拟清理失败');
        }, '故意失败'));
        $r->register(new MaintenanceTask('good', 60, static fn(): array => ['removed' => 3, 'note' => 'ok'], '正常'));
        $out = $r->run('cli', null, true);
        truthy($out['ran'] === 2, '两个任务都应被尝试');
        truthy($out['results'][0]['ok'] === false && $out['results'][1]['ok'] === true, '失败不应中断后续任务');
        $l = $r->ledger()['tasks'];
        truthy(str_contains((string) $l['bad']['last_error'], '模拟清理失败'), '失败现场未记入台账');
        truthy((int) $l['good']['total_removed'] === 3, '正常任务结果未累计');
    } finally { $wipe($d); }
});

check('B1·G7 间隔与 --force：未到期跳过，强制则重跑', function () use ($tmpRoot, $wipe): void {
    $d = $tmpRoot();
    try {
        $r = new MaintenanceRegistry($d);
        $r->register(new MaintenanceTask('tick', 3600, static fn(): array => ['removed' => 1, 'note' => ''], '计时'));
        $now = 1000000;
        truthy($r->run('cli', null, false, $now)['ran'] === 1, '首次应执行');
        truthy($r->run('cli', null, false, $now + 60)['ran'] === 0, '未到期不应重复执行');
        truthy($r->run('cli', null, true, $now + 60)['ran'] === 1, '--force 应忽略间隔');
        truthy((int) $r->ledger()['tasks']['tick']['runs'] === 2, '累计次数应为 2');
    } finally { $wipe($d); }
});

check('B1·G8 锁：拿不到 flock 时跳过本轮而不排队阻塞', function () use ($tmpRoot, $wipe): void {
    $d = $tmpRoot();
    try {
        $r = new MaintenanceRegistry($d);
        $r->register(new MaintenanceTask('tick', 60, static fn(): array => ['removed' => 1, 'note' => ''], '计时'));
        @mkdir($d . '/storage/maintenance', 0775, true);
        $h = fopen($d . '/storage/maintenance/run.lock', 'c');
        flock($h, LOCK_EX | LOCK_NB);
        $out = $r->run('cli', null, true);
        flock($h, LOCK_UN); fclose($h);
        truthy($out['skipped'] === 'locked' && $out['ran'] === 0, '持锁时应返回 skipped=locked');
    } finally { $wipe($d); }
});

check('B1·G9 logs.rotate 真删过期日志，保留当天', function () use ($tmpRoot, $wipe): void {
    $d = $tmpRoot();
    try {
        $old = $d . '/storage/logs/2020-01-01.log';
        $new = $d . '/storage/logs/' . date('Y-m-d') . '.log';
        file_put_contents($old, "old\n"); file_put_contents($new, "new\n");
        $r = new MaintenanceRegistry($d);
        foreach (BuiltinTasks::all($d, ['log_retention_days' => 30]) as $t) {
            if ($t->id() === 'logs.rotate') $r->register($t);
        }
        $out = $r->run('cli', 'logs.rotate', true);
        truthy($out['results'][0]['removed'] === 1, '应只删掉 1 个过期日志');
        truthy(!is_file($old) && is_file($new), '过期文件应删除、当天文件应保留');
    } finally { $wipe($d); }
});

check('B1·G10 CLI 只读子命令不写台账', function () use ($root): void {
    $file = $root . '/storage/maintenance/state.json';
    $before = is_file($file) ? (string) file_get_contents($file) : null;
    foreach (['list', 'status', 'verify'] as $mode) {
        exec('php ' . escapeshellarg($root . '/tools/maintenance.php') . ' ' . $mode . ' 2>&1', $o, $code);
        truthy($code === 0, "maintenance.php {$mode} 应以 0 退出");
    }
    $after = is_file($file) ? (string) file_get_contents($file) : null;
    truthy($before === $after, '只读子命令不应改动台账');
});

check('A2·G11 静态：跨表写禁用 / 重置口令都走事务会话', function () use ($root): void {
    $src = (string) file_get_contents($root . '/systems/User/AdminOperations.php');
    truthy(str_contains($src, 'DataSessionInterface'), '未声明事务会话契约');
    foreach (['disable', 'resetPassword'] as $method) {
        $at = strpos($src, 'private function ' . $method . '(');
        truthy($at !== false, "缺少方法 {$method}");
        $next = strpos($src, "\n    private function ", $at + 10);
        $body = substr($src, $at, $next === false ? strlen($src) - $at : $next - $at);
        truthy(str_contains($body, '->transaction('), "{$method} 的跨表写未纳入事务边界");
    }
    $us = (string) file_get_contents($root . '/systems/User/UserSystem.php');
    truthy(str_contains($us, 'resolve(DataSessionInterface::class)'), '装配处未注入事务会话');
});

check('A2·G12 静态：裸 beginTransaction 锁死历史白名单，禁止新增', function () use ($root): void {
    $allowed = ['systems/Data/DataSession.php', 'systems/Content/ContentTermStore.php',
                'systems/Member/PdoProfileStore.php', 'systems/Metric/PdoMetricStore.php'];
    $seen = [];
    foreach (glob($root . '/systems/*/*.php') ?: [] as $f) {
        if (str_contains((string) file_get_contents($f), 'beginTransaction(')) {
            $seen[] = str_replace($root . '/', '', $f);
        }
    }
    sort($seen); $sorted = $allowed; sort($sorted);
    truthy($seen === $sorted, '裸事务集合漂移：' . implode(', ', $seen));
});
