<?php
declare(strict_types=1);

/**
 * 79 · 发版流水线工具（v0.65）
 *
 * 把「构建签名包 → 打 tag → 建 Release → 平移演练版本线」固化为 tools/release.php。
 * 守卫只锁守得住的部分：结构落点、模板不带写死版本、plan / build / verify --local /
 * shift --dry 四条只读或仅写 /tmp 的子命令真实可跑，且绝不改动工作区文件。
 * 真正写 GitHub 的 publish / run 由收尾流程实跑，守卫刻意不触网（网络抖动不该让守卫变红）。
 */

echo "\n=== 79. 发版流水线工具（tools/release.php）===\n";

$tool = $root . '/tools/release.php';
$remote = $root . '/tools/release/Remote.php';
$tplDir = $root . '/tools/release';
$version = (string) (json_decode((string) file_get_contents($root . '/core/version.json'), true)['version'] ?? '');
$tpls = ['plugin.json.stub', 'marker.txt.stub', 'DemoPlugin.php.stub',
         'CHANGELOG.md.stub', 'RELEASE.md.stub', 'body.md.stub'];

/** 以工作区根为 cwd 跑工具，返回 [退出码, 输出]。只用于无网络副作用的子命令。 */
$cli = static function (string $args) use ($root): array {
    $out = [];
    $rc = 0;
    exec('cd ' . escapeshellarg($root) . ' && ' . escapeshellarg(PHP_BINARY)
        . ' tools/release.php ' . $args . ' 2>&1', $out, $rc);
    return [$rc, implode("\n", $out)];
};

check('R1 · 薄入口 + 协作者齐备，且两个文件零 Wx use 边（协作图不产生悬空引用）', function () use ($tool, $remote): void {
    truthy(is_file($tool) && is_file($remote), '缺 tools/release.php 或 tools/release/Remote.php');
    $a = (string) file_get_contents($tool);
    $b = (string) file_get_contents($remote);
    truthy(str_contains($a, "require_once __DIR__ . '/release/Remote.php'"), '入口未引入协作者');
    truthy(!str_contains($a, 'use Wx\\') && !str_contains($b, 'use Wx\\'), '工具出现 Wx 命名空间 use 边');
    truthy(!str_contains($b, '$argv'), '协作者不应自带 CLI 分派');
});

check('R2 · 六个子命令齐备，run 必须显式 --yes 才写 GitHub', function () use ($tool): void {
    $s = (string) file_get_contents($tool);
    foreach (['plan', 'build', 'publish', 'shift', 'verify', 'run'] as $cmd) {
        truthy(str_contains($s, "'" . $cmd . "'"), "子命令 {$cmd} 未登记");
    }
    truthy(str_contains($s, '--yes'), 'run 缺少显式确认门槛');
    truthy(str_contains($s, 'core/version.json'), '版本未取自唯一真源');
});

check('R3 · 六个模板外置、全部用占位符注入版本，不含写死版本号', function () use ($tplDir, $tpls): void {
    foreach ($tpls as $t) {
        $p = $tplDir . '/' . $t;
        truthy(is_file($p), "缺模板 {$t}");
        $s = (string) file_get_contents($p);
        truthy(str_contains($s, '{{VERSION}}'), "{$t} 未使用版本占位符");
        truthy(preg_match('/0\.\d+\.\d+/', $s) === 0, "{$t} 写死了版本号");
    }
});

check('R4 · plan 只读输出版本 / tag / 资产 / 演练线，且不改动 remote-update.php', function () use ($cli, $version, $root): void {
    $before = md5_file($root . '/tools/remote-update.php');
    [$rc, $txt] = $cli('plan');
    truthy($rc === 0, "plan 退出码 {$rc}：{$txt}");
    foreach ([$version, 'v' . $version, "Mini_Blog-{$version}.zip", 'RELEASE-', '演练线'] as $needle) {
        truthy(str_contains($txt, $needle), "plan 输出缺 {$needle}");
    }
    truthy(md5_file($root . '/tools/remote-update.php') === $before, 'plan 修改了工作区文件');
});

check('R5 · build 产出签名包：包内版本逐字一致、清单恰好覆盖全部载荷、签名齐全', function () use ($cli, $version): void {
    $out = sys_get_temp_dir() . '/wx-rel-guard-' . bin2hex(random_bytes(4));
    try {
        [$rc, $txt] = $cli('build --out ' . escapeshellarg($out));
        truthy($rc === 0, "build 退出码 {$rc}：{$txt}");
        $zip = $out . "/Mini_Blog-{$version}.zip";
        truthy(is_file($zip), '未生成 zip 资产');
        $dir = $out . "/Mini_Blog-{$version}/Mini_Blog";
        $meta = json_decode((string) file_get_contents($dir . '/plugin.json'), true);
        truthy(($meta['version'] ?? '') === $version, 'plugin.json 版本不符');
        truthy(str_contains((string) file_get_contents($dir . '/marker.txt'), 'v' . $version), 'marker 版本不符');
        truthy(str_contains((string) file_get_contents($dir . '/DemoPlugin.php'), "const VERSION = '{$version}'"), '类常量版本不符');
        truthy(is_file($dir . "/RELEASE-{$version}.md"), '增量载荷缺失');
        $manifest = json_decode((string) file_get_contents($dir . '/manifest.sha256'), true);
        truthy(is_array($manifest), '缺 manifest.sha256');
        foreach (['plugin.json', 'marker.txt', 'DemoPlugin.php', 'CHANGELOG.md', "RELEASE-{$version}.md"] as $f) {
            truthy(isset($manifest[$f]), "清单缺 {$f}");
        }
        truthy(count($manifest) === 5, '清单条目数不为 5（漏登记或有清单外文件）');
        truthy(count(glob($dir . '/*.json') ?: []) >= 2, '缺 HMAC 签名文件');
    } finally {
        exec('rm -rf ' . escapeshellarg($out));
    }
});

check('R6 · verify --local 纯本地零失败，且不触网不改工作区', function () use ($cli, $root): void {
    $before = md5_file($root . '/tools/remote-update.php');
    [$rc, $txt] = $cli('verify --local');
    truthy($rc === 0 && str_contains($txt, '0 failed'), "verify --local 未全绿：{$txt}");
    truthy(md5_file($root . '/tools/remote-update.php') === $before, 'verify 修改了工作区文件');
});

check('R7 · shift --dry 只读预演，绝不落盘', function () use ($cli, $root): void {
    $before = md5_file($root . '/tools/remote-update.php');
    [$rc, $txt] = $cli('shift --dry');
    truthy($rc === 0, "shift --dry 退出码 {$rc}：{$txt}");
    truthy(md5_file($root . '/tools/remote-update.php') === $before, 'shift --dry 竟然写盘');
});

check('R8 · shift 用 strtr 一次性映射并断言行数不变（防版本连锁替换与 SLOC 漂移）', function () use ($tool): void {
    $s = (string) file_get_contents($tool);
    truthy(str_contains($s, 'strtr($src0'), 'shift 未使用 strtr 一次性映射');
    truthy(str_contains($s, '行数变化会令 SLOC 漂移'), 'shift 缺少行数不变断言');
    truthy(str_contains($s, 'rl_payload_of($src0)'), 'shift 未统一解析载荷文件名');
});

check('R9 · publish 幂等：tag 锚点不符即中止，Release / 资产已存在即跳过，写操作带重试', function () use ($remote): void {
    $s = (string) file_get_contents($remote);
    truthy(str_contains($s, '已存在但指向'), '缺 tag 锚点保护');
    truthy(str_contains($s, 'Release 已存在'), '缺 Release 跳过分支');
    truthy(str_contains($s, '资产已存在，跳过'), '缺资产跳过分支');
    truthy(str_contains($s, 'rl_retry('), '缺网络重试');
});

check('R10 · 项目树已登记 release.php 与 release 目录（组 17 白名单）', function () use ($root): void {
    $s = (string) file_get_contents($root . '/tests/groups/17-project-hygiene.php');
    truthy(str_contains($s, "'release.php'"), '白名单缺 release.php');
    truthy(str_contains($s, "'release'"), '白名单缺 release 目录');
});
