<?php
declare(strict_types=1);

/**
 * 发版流水线 CLI —— 把「构建签名包 → 打项目版本 tag → 建 Release → 平移演练版本线」
 * 固化成一条可重跑命令，替代每次发版手工拼二十多处替换与四条 gh 命令。
 *
 *   php tools/release.php plan                       只读预演（纯本地、不联网、不写文件）
 *   php tools/release.php build [--out DIR]          构建签名包到临时目录（幂等，可反复重建）
 *   php tools/release.php publish [--sha SHA]        幂等打 tag / 建 Release / 上传资产（带重试）
 *   php tools/release.php shift [--dry]              平移 tools/remote-update.php 演练版本线
 *   php tools/release.php verify [--local]           只读核对版本锚点 / tag / Release / 资产 / 演练线
 *   php tools/release.php run [--sha SHA] --yes      build → publish → shift → verify 全流程
 *
 * 纪律：
 *   - 版本唯一真源是 core/version.json；演练线「上一版」默认取 remote-update.php 的 V2；
 *   - 包模板外置在 tools/release/*.stub，工具本身只做替换与编排，不写死任何版本号；
 *   - 所有写操作幂等：tag / Release / 资产已存在且正确即跳过，演练线已对齐则不重写；
 *   - shift 用 strtr 一次性映射（不递归），避免 0.63→0.64→0.65 连锁误替换，
 *     且强制断言行数不变，保证 SLOC 不因发版漂移；
 *   - 刻意不引导运行时内核：发版是仓库事实，与站点装配结果无关。
 */

// GitHub 发布 / 核验落在协作者，入口只保留本地编排与 CLI 分派。
require_once __DIR__ . '/release/Remote.php';

function rl_root(): string { return dirname(__DIR__); }
function rl_tpl(): string  { return __DIR__ . '/release'; }

function rl_need(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

/** 解析 --key value / --key=value / --flag。 */
function rl_options(array $args): array
{
    $out = [];
    for ($i = 0; $i < count($args); $i++) {
        $a = $args[$i];
        if (!str_starts_with($a, '--')) continue;
        $k = substr($a, 2);
        if (str_contains($k, '=')) {
            [$k, $v] = explode('=', $k, 2);
            $out[$k] = $v;
        } elseif (isset($args[$i + 1]) && !str_starts_with($args[$i + 1], '--')) {
            $out[$k] = $args[++$i];
        } else {
            $out[$k] = true;
        }
    }
    return $out;
}

function rl_version(): string
{
    $v = json_decode((string) file_get_contents(rl_root() . '/core/version.json'), true);
    $s = (string) ($v['version'] ?? '');
    rl_need($s !== '', 'core/version.json 缺 version');
    return $s;
}

/** 读 remote-update.php 里的 const V1 / V2 字面量，不用正则，避免多一层解析实现。 */
function rl_const(string $src, string $name): string
{
    $p = strpos($src, 'const ' . $name);
    rl_need($p !== false, "remote-update.php 缺 const {$name}");
    $q = strpos($src, "'", $p);
    $e = strpos($src, "'", $q + 1);
    return substr($src, $q + 1, $e - $q - 1);
}

/** remote-update.php 里被断言检查的增量载荷文件名集合。 */
function rl_payload_of(string $src): array
{
    preg_match_all('#/plugins/demo/([A-Za-z0-9._-]+\.md)#', $src, $m);
    return array_values(array_unique($m[1] ?? []));
}

function rl_repo(): string
{
    $out = [];
    exec('git -C ' . escapeshellarg(rl_root()) . ' remote get-url origin 2>/dev/null', $out, $rc);
    $url = trim($out[0] ?? '');
    $p = strpos($url, 'github.com');
    rl_need($p !== false, 'origin 不是 GitHub 仓库：' . $url);
    $tail = ltrim(substr($url, $p + strlen('github.com')), ':/');
    return trim((string) preg_replace('#\.git$#', '', $tail), '/');
}

function rl_head(): string
{
    $out = [];
    exec('git -C ' . escapeshellarg(rl_root()) . ' rev-parse HEAD 2>/dev/null', $out, $rc);
    return trim($out[0] ?? '');
}

/** 只读计划：版本、锚点、演练线平移前后、待办。 */
function rl_plan(array $opt): array
{
    $version = (string) ($opt['version'] ?? rl_version());
    $src = (string) file_get_contents(rl_root() . '/tools/remote-update.php');
    $oldV1 = rl_const($src, 'V1');
    $oldV2 = rl_const($src, 'V2');
    // V2 已等于当前版本 ⇒ 演练线已对齐，prev 保持 V1、不做任何平移；
    // 否则把演练线整体后推一格：新起点 = 旧 V2，新终点 = 当前版本。
    $aligned = ($oldV2 === $version);
    $prev = (string) ($opt['prev'] ?? ($aligned ? $oldV1 : $oldV2));
    return [
        'version'      => $version,
        'prev'         => $prev,
        'tag'          => 'v' . $version,
        'asset'        => "Mini_Blog-{$version}.zip",
        'payload'      => "RELEASE-{$version}.md",
        'repo'         => rl_repo(),
        'sha'          => (string) ($opt['sha'] ?? rl_head()),
        'old_v1'       => $oldV1,
        'old_v2'       => $oldV2,
        'shift_needed' => !$aligned,
    ];
}

function rl_print_plan(array $p): void
{
    echo "=== 发版计划（只读，版本真源 core/version.json） ===\n";
    printf("  版本      %s\n", $p['version']);
    printf("  上一版    %s（remote-update.php 的 V2）\n", $p['prev']);
    printf("  仓库      %s\n", $p['repo']);
    printf("  tag       %s → %s\n", $p['tag'], substr($p['sha'], 0, 7));
    printf("  资产      %s\n", $p['asset']);
    printf("  增量载荷  %s\n", $p['payload']);
    printf("  演练线    %s → %s  ⇒  %s → %s（%s）\n",
        $p['old_v1'], $p['old_v2'], $p['prev'], $p['version'],
        $p['shift_needed'] ? '需平移' : '已对齐，跳过');
    echo "  待办      build → publish → shift → verify → php tools/remote-update.php 实跑\n";
}

function rl_vars(array $p): array
{
    return [
        '{{VERSION}}' => $p['version'], '{{PREV}}' => $p['prev'],
        '{{TAG}}' => $p['tag'], '{{ASSET}}' => $p['asset'],
        '{{PAYLOAD}}' => $p['payload'], '{{SHA}}' => $p['sha'],
    ];
}

/** 构建包：模板替换 → 调 sign-package.php 生成清单与签名 → 打 zip。返回 zip 绝对路径。 */
function rl_build(array $p, string $out): string
{
    $out = rtrim($out, '/');
    rl_need(is_dir($out) || mkdir($out, 0775, true), '无法创建输出目录 ' . $out);
    $stage = $out . '/Mini_Blog-' . $p['version'];
    $dir = $stage . '/Mini_Blog';
    exec('rm -rf ' . escapeshellarg($stage));
    rl_need(mkdir($dir, 0775, true), '无法创建包目录');

    $files = [
        'plugin.json.stub'    => 'plugin.json',
        'marker.txt.stub'     => 'marker.txt',
        'DemoPlugin.php.stub' => 'DemoPlugin.php',
        'CHANGELOG.md.stub'   => 'CHANGELOG.md',
        'RELEASE.md.stub'     => $p['payload'],
    ];
    foreach ($files as $tpl => $name) {
        $body = (string) @file_get_contents(rl_tpl() . '/' . $tpl);
        rl_need($body !== '', '模板缺失 ' . $tpl);
        file_put_contents($dir . '/' . $name, strtr($body, rl_vars($p)));
    }

    $o = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(rl_root() . '/tools/sign-package.php')
        . ' ' . escapeshellarg($dir) . ' ' . escapeshellarg('gh-remote-update-verify-secret') . ' 2>&1', $o, $rc);
    rl_need($rc === 0, '签名失败：' . implode(' ', $o));

    $zip = $out . '/' . $p['asset'];
    @unlink($zip);
    exec('cd ' . escapeshellarg($stage) . ' && zip -q -r ' . escapeshellarg($zip) . ' Mini_Blog 2>&1', $o2, $rc2);
    rl_need($rc2 === 0 && is_file($zip), '打包失败：' . implode(' ', $o2));
    echo "  build 完成：{$zip}（" . count($files) . " 个载荷 + 清单 + 签名）\n";
    return $zip;
}

/** 平移演练版本线：一次性版本映射 + 载荷名替换，行数必须不变。 */
function rl_shift(array $p, bool $dry): void
{
    $file = rl_root() . '/tools/remote-update.php';
    $src0 = (string) file_get_contents($file);
    if (!$p['shift_needed']) {
        echo "  shift 跳过：演练线已是 {$p['prev']} → {$p['version']}\n";
        return;
    }
    $olds = rl_payload_of($src0);
    rl_need(count($olds) === 1, '无法唯一确定当前载荷文件名：' . implode(',', $olds));

    $src = strtr($src0, [$p['old_v1'] => $p['prev'], $p['old_v2'] => $p['version']]);
    $src = str_replace($olds[0], $p['payload'], $src);
    rl_need(substr_count($src, "\n") === substr_count($src0, "\n"), '行数变化会令 SLOC 漂移，中止');
    rl_need(rl_const($src, 'V1') === $p['prev'] && rl_const($src, 'V2') === $p['version'], '平移后版本常量不符');
    rl_need(!str_contains($src, $olds[0]), '旧载荷名残留');
    rl_need(substr_count($src, $p['payload']) >= 2, '新载荷名未写入断言');

    echo "  shift 版本映射：{$p['old_v1']} → {$p['prev']}，{$p['old_v2']} → {$p['version']}\n";
    echo "  shift 载荷改名：{$olds[0]} → {$p['payload']}\n";
    if ($dry) {
        echo "  shift 预演（未写文件），行数不变：" . substr_count($src0, "\n") . " 行\n";
        return;
    }
    file_put_contents($file, $src);
    echo "  shift 已写入 tools/remote-update.php（行数不变）\n";
}

$command = (string) ($argv[1] ?? 'plan');
$opt = rl_options(array_slice($argv, 2));
$known = ['plan', 'build', 'publish', 'shift', 'verify', 'run'];
if (!in_array($command, $known, true)) {
    fwrite(STDERR, "用法：php tools/release.php [plan|build|publish|shift|verify|run] [选项]\n");
    exit(2);
}
$p = rl_plan($opt);
$outDir = (string) ($opt['out'] ?? sys_get_temp_dir() . '/miniblog-release');
$exit = 0;

if ($command === 'plan') {
    rl_print_plan($p);
} elseif ($command === 'build') {
    rl_build($p, $outDir);
} elseif ($command === 'publish') {
    rl_print_plan($p);
    rl_publish($p, rl_build($p, $outDir));
} elseif ($command === 'shift') {
    rl_shift($p, ($opt['dry'] ?? false) === true);
} elseif ($command === 'verify') {
    $exit = rl_report(rl_verify($p, ($opt['local'] ?? false) === true));
} elseif ($command === 'run') {
    rl_need(($opt['yes'] ?? false) === true, 'run 会写 GitHub tag / Release，请显式加 --yes');
    rl_print_plan($p);
    $zip = rl_build($p, $outDir);
    rl_publish($p, $zip);
    rl_shift($p, false);
    $exit = rl_report(rl_verify($p, false));
}
exit($exit);
