<?php
declare(strict_types=1);

/**
 * GitHub 发版源升级 / 回滚实跑验证 CLI —— v0.63（版本线对齐项目版本 0.62.0 → 0.63.0）。
 *
 *   php tools/remote-update.php
 *
 * 验证「版本包从 GitHub Releases 发布、站点从 GitHub 升级与回滚」两个流程真实可用：
 *
 *   1. 从 GitHub Releases 匿名下载签名包资产，落到隔离站点 storage/updates/ 暂存区
 *      （站点根目录内，满足 PackageVerifier::assertSource 的路径边界）；
 *   2. lifecycle.install → activate：GitHub 包真实落位到 plugins/demo；
 *   3. lifecycle.upgrade：拉 v0.63.0 新 Release 升级，文件内容与版本记录同步切换；
 *   4. lifecycle.rollback：回滚到 0.62.0 快照，旧内容完整恢复（降级可用）；
 *   5. 回滚后再从 GitHub 重新下载 v0.63.0 升回（回滚不是单行道）；
 *   6. 篡改防护：改动包内文件后升级被完整性校验拒绝（42201，fail-closed）；
 *   7. lifecycle.verify / releases 只读探查复核全程留痕。
 *
 * 与 journey.php 同一纪律：
 *   - 全程走 Kernel::handle(Request) 真实请求管线，不直接调容器方法；
 *   - 只在 /tmp 隔离站点 + 独立表前缀落地，结束删表删目录、工作区零残留；
 *   - 退出码 0 = 全绿。
 *
 * 发版约定：签名密钥是演示值，生产环境由环境注入 config.lifecycle.signature_secret，
 * 包源即本仓库（Lisir2002/Mini_Blog）的 Releases 页：tag 就是项目版本（v<ver>），
 * 资产名 Mini_Blog-<ver>.zip，包内清单版本与项目版本逐字一致
 */

use Wx\Core\Kernel;
use Wx\Tools\Journey\InstallStage;
use Wx\Tools\Journey\JourneyState;
use Wx\Tools\Journey\Probe;
use Wx\Tools\Journey\Site;

$root = require dirname(__DIR__) . '/core/bootstrap.php';
foreach (['Site', 'Probe', 'JourneyState', 'InstallStage'] as $class) {
    require_once __DIR__ . '/journey/' . $class . '.php';
}

const GH_REPO   = 'Lisir2002/Mini_Blog';
const GH_TAG_PFX = 'v';              // 项目版本 tag（v0.62.0 / v0.63.0），与代码 commit 一一对应
const GH_SECRET = 'gh-remote-update-verify-secret'; // 演示密钥；生产由环境注入
const V1        = '0.62.0';
const V2        = '0.63.0';

/** 带重试的 GitHub 资产下载（沙箱到 GitHub 的 TLS 链路偶发中断，curl 自带重试 + 完整性校验兜底）。 */
$fetchRelease = static function (string $version, string $updatesDir): string {
    if (!is_dir($updatesDir)) mkdir($updatesDir, 0775, true);
    $workDir = $updatesDir . '/v' . $version;
    $zip = $workDir . '.zip';
    $url = 'https://github.com/' . GH_REPO . "/releases/download/" . GH_TAG_PFX . "{$version}/Mini_Blog-{$version}.zip";
    $done = false;
    for ($i = 0; $i < 5 && !$done; $i++) {
        exec('curl -fsSL --retry 6 --retry-all-errors --retry-delay 2 --connect-timeout 20 '
            . escapeshellarg($url) . ' -o ' . escapeshellarg($zip), $out, $code);
        if ($code === 0 && is_file($zip) && filesize($zip) > 0) {
            exec('unzip -tqq ' . escapeshellarg($zip), $tout, $tcode);
            $done = $tcode === 0; // 半截文件不算下载成功，删掉重来
        }
        if (!$done) { @unlink($zip); sleep(2); }
    }
    if (!$done) throw new RuntimeException("GitHub 资产下载失败（exit {$code}）：{$url}");
    exec('unzip -q -o ' . escapeshellarg($zip) . ' -d ' . escapeshellarg($workDir), $out, $code);
    if ($code !== 0) throw new RuntimeException("解压失败（exit {$code}）：{$zip}");
    $src = $workDir . '/Mini_Blog';
    if (!is_file($src . '/plugin.json')) {
        throw new RuntimeException('解压产物缺少 plugin.json：' . $src);
    }
    return $src;
};

$site = new Site($root);
$p = new Probe();
$s = new JourneyState();

// 站点同构装配映射：真实部署里，站点根自己的 bootstrap 负责自己 plugins/ 下的类；
// 本工具进程的 autoloader 指向工作区根，故为隔离站点副本补一条映射（仅 demo 前缀），
// 使「GitHub 包落位站点副本 → 新内核装配实例化」这条链与生产形态一致。
spl_autoload_register(static function (string $class) use ($site): void {
    $prefix = 'Wx\\Plugins\\demo\\';
    if (str_starts_with($class, $prefix)) {
        $file = $site->dir() . '/plugins/demo/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($file)) require $file;
    }
});

/** 更新流程专用内核：与真实部署一致只读 local.php，验签密钥由「环境注入」（覆盖层模拟）。 */
$updateKernel = static function () use ($site): Kernel {
    return $site->kernel(['lifecycle' => [
        'require_checksum'  => true,
        'require_signature' => true,
        'signature_secret'  => GH_SECRET,
    ]]);
};

echo "\n=== GitHub 发版源升级 / 回滚实跑（repo: " . GH_REPO . "）===\n";
echo "  隔离站点：{$site->dir()}\n";

try {
    // 0. 隔离站点五步安装 + 真实管理员（复用旅程安装段）
    (new InstallStage())->run($p, $site, $s);

    $res = $p->post($updateKernel(), '/api/v1/auth/token',
        ['username' => 'webmaster', 'password' => 'Journey-Pass-2026']);
    $bearer = (string) ($p->data($res, 'auth.token')['token'] ?? '');
    $p->ok($bearer !== '', '签发更新流程令牌失败');
    $H = ['Authorization' => 'Bearer ' . $bearer];

    // 1. GitHub 发版源可达：两个 Release 资产匿名可下载
    $p->step('GitHub 发版源可达：v0.62.0 / v0.63.0 资产匿名下载', static function () use ($p, $site, $fetchRelease): void {
        $updates = $site->dir() . '/storage/updates';
        foreach ([V1, V2] as $v) {
            $src = $fetchRelease($v, $updates);
            $meta = json_decode((string) file_get_contents($src . '/plugin.json'), true);
            $p->ok(($meta['version'] ?? '') === $v, "v{$v} 清单版本不符：" . ($meta['version'] ?? '?'));
            $p->ok(is_file($src . '/signature.json') && is_file($src . '/manifest.sha256'),
                "v{$v} 资产缺签名 / 完整性清单");
        }
        $p->note('匿名 curl 下载 + 解压即得站点根内包源（storage/updates/v<ver>/Mini_Blog）');
    });

    // 2. 安装 v0.62.0：GitHub 包 → 验签安装
    $p->step('lifecycle.install：GitHub v0.62.0 验签安装并落位 plugins/demo', static function () use ($p, $site, $updateKernel, $fetchRelease, $H): void {
        $src = $fetchRelease(V1, $site->dir() . '/storage/updates');
        $r = $p->post($updateKernel(), '/api/v1/lifecycle/install',
            ['type' => 'plugin', 'source' => $src], [], $H);
        $d = $p->data($r, 'lifecycle.install');
        $p->ok(($d['slug'] ?? '') === 'demo' && ($d['version'] ?? '') === V1,
            '安装响应版本不符：' . json_encode($d));
        $p->ok(($d['state'] ?? '') === 'installed', '安装后状态应为 installed');
        $marker = (string) file_get_contents($site->dir() . '/plugins/demo/marker.txt');
        $p->ok(str_contains($marker, 'v' . V1), '落位文件内容不是 v' . V1 . '：' . $marker);
        $p->note('slug=demo · version=' . V1 . ' · marker 已落位');
    });

    // 3. 激活：v0.59 门禁放行 active，真实装配可加载
    $p->step('lifecycle.activate：active 装载门禁放行，新内核装配 demo 包不崩', static function () use ($p, $site, $updateKernel, $H): void {
        $r = $p->post($updateKernel(), '/api/v1/lifecycle/activate',
            ['type' => 'plugin', 'slug' => 'demo'], [], $H);
        $d = $p->data($r, 'lifecycle.activate');
        $p->ok(($d['state'] ?? '') === 'active', '激活后状态应为 active');
        // 真实装配：类缺失 / 清单错都会在 boot 抛 RuntimeException
        $k = $updateKernel();
        $k->boot();
        $p->note('demo 已 active · PackageLoader 实例化 Wx\Plugins\demo\DemoPlugin 成功');
    });

    // 4. 升级：拉新 Release v0.63.0 → lifecycle.upgrade
    $p->step('lifecycle.upgrade：GitHub v0.63.0 升级，文件与版本记录同步切换', static function () use ($p, $site, $updateKernel, $fetchRelease, $H): void {
        $src = $fetchRelease(V2, $site->dir() . '/storage/updates');
        $r = $p->post($updateKernel(), '/api/v1/lifecycle/upgrade',
            ['type' => 'plugin', 'slug' => 'demo', 'source' => $src], [], $H);
        $d = $p->data($r, 'lifecycle.upgrade');
        $p->ok(($d['version'] ?? '') === V2, '升级后版本应为 ' . V2 . '：' . json_encode($d));
        $p->ok(($d['state'] ?? '') === 'active', '升级不应改变 active 状态');
        $marker = (string) file_get_contents($site->dir() . '/plugins/demo/marker.txt');
        $p->ok(str_contains($marker, 'v' . V2), '升级后文件内容仍是旧版：' . $marker);
        $p->ok(is_file($site->dir() . '/plugins/demo/CHANGELOG.md'), 'v0.63.0 新增文件未随升级落位');
        $p->note('version 0.62.0 → 0.63.0 · active 保持 · CHANGELOG.md 新文件随包落位');
    });

    // 5. 回滚：快照恢复到 0.62.0（降级可用）
    $p->step('lifecycle.rollback：回滚到 0.62.0 快照，旧内容完整恢复', static function () use ($p, $site, $updateKernel, $H): void {
        $r = $p->post($updateKernel(), '/api/v1/lifecycle/rollback',
            ['type' => 'plugin', 'slug' => 'demo'], [], $H);
        $d = $p->data($r, 'lifecycle.rollback');
        $p->ok(($d['version'] ?? '') === V1, '回滚后版本应为 ' . V1 . '：' . json_encode($d));
        $marker = (string) file_get_contents($site->dir() . '/plugins/demo/marker.txt');
        $p->ok(str_contains($marker, 'v' . V1), '回滚后文件内容不是 v' . V1);
        $p->ok(!is_file($site->dir() . '/plugins/demo/CHANGELOG.md'), '回滚未移除 v0.63.0 新增文件');
        $p->note('version 0.63.0 → 0.62.0 · CHANGELOG.md 已随旧快照移除');
    });

    // 6. 篡改防护：当前 0.62.0，用被篡改的 v0.63.0 包升级——版本门槛放行后由完整性校验拦截
    $p->step('篡改防护：改写 v0.63.0 包内文件后升级被完整性校验拒绝（42201）', static function () use ($p, $site, $updateKernel, $fetchRelease, $H): void {
        $src = $fetchRelease(V2, $site->dir() . '/storage/updates');
        file_put_contents($src . '/marker.txt', 'tampered!');
        $r = $p->post($updateKernel(), '/api/v1/lifecycle/upgrade',
            ['type' => 'plugin', 'slug' => 'demo', 'source' => $src], [], $H);
        $p->ok(($r['json']['code'] ?? null) === 42201,
            '篡改包未被拒绝，实际：' . substr($r['body'], 0, 200));
        $cur = (string) file_get_contents($site->dir() . '/plugins/demo/marker.txt');
        $p->ok(str_contains($cur, 'v' . V1), '被拒的升级污染了当前版本文件');
        $p->note('当前 0.62.0 · 篡改包版本 0.63.0 通过版本门槛后，完整性校验先行拦截（42201）· 现场未被污染');
    });

    // 7. 回滚后再升级：重新从 GitHub 拉 v0.63.0（回滚不是单行道）
    $p->step('回滚后再升级：重新匿名下载 GitHub v0.63.0 升回成功', static function () use ($p, $site, $updateKernel, $fetchRelease, $H): void {
        // 清空暂存区再下载，证明升级源确实来自 GitHub 而非本地残留
        exec('rm -rf ' . escapeshellarg($site->dir() . '/storage/updates'));
        $src = $fetchRelease(V2, $site->dir() . '/storage/updates');
        $r = $p->post($updateKernel(), '/api/v1/lifecycle/upgrade',
            ['type' => 'plugin', 'slug' => 'demo', 'source' => $src], [], $H);
        $d = $p->data($r, 'lifecycle.upgrade(again)');
        $p->ok(($d['version'] ?? '') === V2, '再次升级后版本应为 ' . V2);
        $p->note('暂存区已清空重建 · v0.63.0 资产重新自 GitHub 下载 · 升回成功');
    });

    // 8. 只读探查：verify + releases 全程留痕
    $p->step('lifecycle.verify / releases：当前单元完整性通过，历史含三个版本快照', static function () use ($p, $updateKernel, $H): void {
        $v = $p->data($p->get($updateKernel(), '/api/v1/lifecycle/verify',
            ['type' => 'plugin', 'slug' => 'demo'], $H), 'lifecycle.verify');
        $p->ok(($v['ok'] ?? false) === true && ($v['mode'] ?? '') === 'sha256', 'verify 未通过：' . json_encode($v));
        $rels = $p->data($p->get($updateKernel(), '/api/v1/lifecycle/releases',
            ['type' => 'plugin', 'slug' => 'demo'], $H), 'lifecycle.releases');
        $list = (array) ($rels['releases'] ?? $rels['items'] ?? $rels);
        $got = [];
        foreach ($list as $e) {
            if (is_array($e)) $got[] = (string) ($e['version'] ?? '');
        }
        foreach ([V1, V2] as $vsn) {
            $p->ok(in_array($vsn, $got, true), '版本历史缺 ' . $vsn . '：' . json_encode($got));
        }
        $p->note('verify ok（sha256）· 版本历史：' . implode(' , ', array_filter($got)));
    });
} finally {
    $site->cleanup();
}

// 工作区零残留：开工前指纹与收工后必须一致
$residue = $site->residue();
$p->ok($residue === [], '工作区残留：' . implode(', ', $residue));
$p->note('隔离站点已清理 · 工作区零残留');

foreach ($p->lines() as $line) echo $line . "\n";
echo "\n=== 结果 ===\n  {$p->passed()} passed, {$p->failed()} failed\n";
exit($p->failed() === 0 ? 0 : 1);
