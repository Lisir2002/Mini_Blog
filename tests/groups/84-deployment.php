<?php
declare(strict_types=1);

/**
 * 84 · 一键部署工具（v0.70）
 *
 * 把「装环境 → 传代码 → 配库 → 装主题 → 起站点 → 验收」固化为 tools/deploy.php。
 * 守卫只锁守得住的部分：结构落点、模板不写死口令 / 版本、plan 只读且不改工作区、
 * 凭据通道（环境变量注入、日志不回显）、幂等语义与核心预算静态锁。
 * 真正写远程的 env / push / serve / install / verify 由部署收尾实跑，守卫刻意不触网。
 */

echo "\n=== 84. 一键部署工具（tools/deploy.php）===\n";

$tool   = $root . '/tools/deploy.php';
$remote = $root . '/tools/deploy/Remote.php';
$tplDir = $root . '/tools/deploy';
$version = (string) (json_decode((string) file_get_contents($root . '/core/version.json'), true)['version'] ?? '');
$stubs = ['env.stub', 'unpack.stub', 'serve.stub', 'install.stub', 'accept.stub'];

/** 以工作区根为 cwd 跑只读子命令，返回 [退出码, 输出]。 */
$cli = static function (string $args) use ($root): array {
    $out = [];
    $rc = 0;
    exec('cd ' . escapeshellarg($root) . ' && ' . escapeshellarg(PHP_BINARY)
        . ' tools/deploy.php ' . $args . ' 2>&1', $out, $rc);
    return [$rc, implode("\n", $out)];
};

check('D1 · 薄入口 + 协作者 + 五个脚本模板齐备，零 Wx use 边 / 协作者不自带 CLI', function () use ($tool, $remote, $tplDir, $stubs): void {
    truthy(is_file($tool) && is_file($remote), '缺 tools/deploy.php 或 tools/deploy/Remote.php');
    $a = (string) file_get_contents($tool);
    $b = (string) file_get_contents($remote);
    truthy(str_contains($a, "require_once __DIR__ . '/deploy/Remote.php'"), '入口未引入协作者');
    truthy(!str_contains($a, 'use Wx\\') && !str_contains($b, 'use Wx\\'), '工具出现 Wx 命名空间 use 边');
    truthy(!str_contains($b, '$argv'), '协作者不应自带 CLI 分派');
    foreach ($stubs as $s) truthy(is_file($tplDir . '/' . $s), "缺脚本模板 {$s}");
});

check('D2 · 七个命令齐备，plan / 其余口令门槛 / run 显式 --yes', function () use ($tool): void {
    $s = (string) file_get_contents($tool);
    foreach (['plan', 'env', 'push', 'serve', 'install', 'verify', 'run'] as $cmd) {
        truthy(str_contains($s, "'" . $cmd . "'"), "子命令 {$cmd} 未登记");
    }
    truthy(str_contains($s, '--yes'), 'run 缺少显式确认门槛');
    truthy(str_contains($s, 'core/version.json'), '版本未取自唯一真源');
    truthy(str_contains($s, 'DP_SSH_PASS') && str_contains($s, 'DP_DB_PASS') && str_contains($s, 'DP_ADMIN_PASS'),
        '三处口令未走环境变量注入');
});

check('D3 · 模板不写死版本号、不写死任何真实口令，只留占位符', function () use ($tplDir, $stubs): void {
    foreach ($stubs as $s) {
        $src = (string) file_get_contents($tplDir . '/' . $s);
        // 排除 127.0.0.1 这类 IP：版本号要求前面不是数字或点
        truthy(preg_match('/(?<![\d.])0\.\d+\.\d+(?![\d.])/', $src) === 0, "{$s} 写死了版本号");
        truthy(!str_contains($src, 'wx_pass_2026') && !str_contains($src, 'admin123'), "{$s} 写死了示例口令");
    }
    $env = (string) file_get_contents($tplDir . '/env.stub');
    $ins = (string) file_get_contents($tplDir . '/install.stub');
    $ver = (string) file_get_contents($tplDir . '/accept.stub');
    truthy(str_contains($env, '{{DBPASS}}') && str_contains($ins, '{{ADMINPASS}}') && str_contains($ver, '{{VERSION}}'),
        '占位符注入点缺失');
    truthy(str_contains($ver, 'CHK '), 'verify 未用 CHK 前缀输出可汇总检查行');
});

check('D4 · 脚本纪律：文档根指向 public/、拒绝敏感目录、幂等与本机安装来源', function () use ($tplDir): void {
    $serve = (string) file_get_contents($tplDir . '/serve.stub');
    $env   = (string) file_get_contents($tplDir . '/env.stub');
    $unp   = (string) file_get_contents($tplDir . '/unpack.stub');
    $ins   = (string) file_get_contents($tplDir . '/install.stub');
    truthy(str_contains($serve, '${ROOT}/public') && str_contains($serve, "ROOT='{{ROOT}}'"), '文档根未指向 public/');
    truthy(str_contains($serve, 'fastcgi_pass'), 'PHP 未交 php-fpm');
    truthy(str_contains($serve, 'sites-enabled/default'), '未移除默认站点');
    preg_match('/location ~ \^\/\([^)]+\)\/ \{ deny all; \}/', $serve, $dm);
    truthy($dm !== [] && !str_contains($dm[1] ?? '', 'api'), 'api 目录不应被 nginx 拒绝（接口须对外，鉴权归框架）');
    truthy(str_contains($env, 'CREATE DATABASE IF NOT EXISTS') && str_contains($env, 'CREATE USER IF NOT EXISTS'),
        '建库建用户不幂等');
    truthy(str_contains($unp, 'config/local.php') && str_contains($unp, 'install.lock'), '解包未保留本机私有状态');
    truthy(!str_contains($unp, '--strip-components'), 'git archive 为扁平结构，解包不可再剥层');
    truthy(str_contains($ins, '127.0.0.1') && str_contains($ins, '$BASE/install')
        && str_contains($ins, 'install.lock'), '安装未走本机向导 / 未幂等');
});

check('D5 · 口令通道：SSH 经 SSHPASS 环境变量、口令临时文件 0600 且用完即删，日志只报就绪态', function () use ($tool, $remote): void {
    $a = (string) file_get_contents($tool);
    $b = (string) file_get_contents($remote);
    truthy(str_contains($b, 'sshpass -e') && str_contains($b, "putenv('SSHPASS='"), 'SSH 口令未走 SSHPASS 环境变量');
    truthy(str_contains($b, 'chmod($tmp, 0600)') && str_contains($b, '@unlink($tmp)'), '口令脚本临时文件未加保护或未清理');
    truthy(str_contains($a, 'dp_ready') && str_contains($a, '已提供') && str_contains($a, '缺失'), '缺口令就绪态展示');
    truthy(!str_contains($a, 'echo $c[\'ssh_pass\']') && !str_contains($a, 'echo $c[\'db_pass\']'), '日志疑似回显口令值');
});

check('D6 · plan 纯只读：退出 0、输出版本 / 目标 / 步骤 / 口令就绪态，且不改工作区', function () use ($cli, $version, $root): void {
    $before = (string) md5_file($root . '/core/version.json');
    [$rc, $txt] = $cli('plan');
    truthy($rc === 0, "plan 退出码 {$rc}：{$txt}");
    foreach ([$version, 'SSH=', 'DB=', 'ADMIN=', 'env → push → serve → install → verify', '缺失'] as $needle) {
        truthy(str_contains($txt, $needle), "plan 输出缺 {$needle}");
    }
    truthy((string) md5_file($root . '/core/version.json') === $before, 'plan 修改了工作区文件');

    // 注入哨兵口令跑 plan：只应显示「已提供」，绝不回显哨兵值本身
    [$rc2, $txt2] = $cli('plan --ssh-pass SENTINEL_SSH --db-pass SENTINEL_DB --admin-pass SENTINEL_ADMIN');
    truthy($rc2 === 0 && str_contains($txt2, '已提供'), '注入口令后 plan 未报告已提供');
    truthy(!str_contains($txt2, 'SENTINEL'), 'plan 回显了注入的口令值');
});

check('D7 · 非 plan 子命令强制口令齐备，run 必须 --yes（缺凭据 / 缺确认即拒）', function () use ($cli): void {
    [$rc1] = $cli('verify');
    truthy($rc1 === 2, '缺口令时 verify 未以 2 拒绝');
    [$rc2] = $cli('run');
    truthy($rc2 === 2, '缺口令时 run 未以 2 拒绝');
    // 给齐口令但 run 无 --yes：应卡在确认门槛而非触网
    [$rc3, $txt3] = $cli('run --ssh-pass x --db-pass y --admin-pass z');
    truthy($rc3 === 2 && str_contains($txt3, '--yes'), 'run 无 --yes 未拒绝或提示不符：' . $txt3);
});

check('D8 · 版本锚点：全仓 version.json / README 徽章 / registry 与部署工具版本一致', function () use ($root, $version, $tool): void {
    truthy(str_contains((string) file_get_contents($root . '/README.md'), 'version-' . $version),
        'README 版本徽章未同步');
    $reg = json_decode((string) file_get_contents($root . '/api/registry.json'), true);
    truthy(($reg['core_version'] ?? '') === $version, 'registry.json core_version 未同步');
    truthy(str_contains((string) file_get_contents($tool), 'core/version.json'), '工具未绑定版本真源');
});

check('D9 · 项目树已登记 deploy.php 与 deploy 目录（组 17 白名单）', function () use ($root): void {
    $src = (string) file_get_contents($root . '/tests/groups/17-project-hygiene.php');
    truthy(str_contains($src, "'deploy'") && str_contains($src, "'deploy.php'"),
        '组 17 白名单未登记部署工具');
});

check('D10 · 静态预算锁：core 24 文件、tools 两文件各不超 240 行、1 主体', function () use ($tool, $remote, $root): void {
    truthy(count(glob($root . '/core/*.php') ?: []) + count(glob($root . '/core/*/*.php') ?: []) === 24,
        'core 文件数不再是 24');
    foreach ([$tool, $remote] as $f) {
        $src = (string) file_get_contents($f);
        $sloc = 0;
        foreach (explode("\n", $src) as $line) {
            $t = trim($line);
            if ($t === '' || str_starts_with($t, '//') || str_starts_with($t, '#')
                || str_starts_with($t, '*') || str_starts_with($t, '/*')) continue;
            $sloc++;
        }
        truthy($sloc <= 240, basename($f) . " 有效代码行 {$sloc} > 240");
    }
});
