<?php
declare(strict_types=1);

/**
 * tools/release.php 的远程协作者 —— GitHub 发布与核验。
 *
 * 刻意不定义 rl_root / rl_repo / rl_const 等公共函数：它们由入口提供，
 * 运行时调用时已定义即可（PHP 函数全局可见）。本文件不单独执行。
 * 与入口一样零 use 边，避免协作图把工具目录解析成悬空引用。
 */

/** gh api 查询：HTTP 404 视为「不存在」返回空串，其余失败抛出（交给重试）。 */
function rl_gh_get(string $path, string $jq): string
{
    $cmd = 'gh api ' . escapeshellarg('repos/' . rl_repo() . '/' . $path)
        . ' --jq ' . escapeshellarg($jq) . ' 2>&1';
    $out = [];
    exec($cmd, $out, $rc);
    $text = trim(implode("\n", $out));
    if ($rc === 0) return trim($text, '"');
    if (str_contains($text, 'HTTP 404') || str_contains($text, 'Not Found')) return '';
    throw new RuntimeException("gh api 查询失败 {$path}：{$text}");
}

function rl_gh_post(string $path, array $payload, string $jq): string
{
    $tmp = (string) tempnam(sys_get_temp_dir(), 'rl');
    file_put_contents($tmp, (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $cmd = 'gh api -X POST ' . escapeshellarg('repos/' . rl_repo() . '/' . $path)
        . ' --input ' . escapeshellarg($tmp) . ' --jq ' . escapeshellarg($jq) . ' 2>&1';
    $out = [];
    exec($cmd, $out, $rc);
    @unlink($tmp);
    $text = trim(implode("\n", $out));
    if ($rc !== 0) throw new RuntimeException("gh api 写入失败 {$path}：{$text}");
    return trim($text, '"');
}

/** 沙箱到 GitHub 的链路偶发 TLS 重置，写操作统一带重试。 */
function rl_retry(string $label, callable $fn, int $n = 4): mixed
{
    for ($i = 1; $i <= $n; $i++) {
        try {
            return $fn();
        } catch (Throwable $e) {
            if ($i === $n) throw $e;
            echo "  {$label} 第 {$i} 次失败，重试：{$e->getMessage()}\n";
            sleep(2);
        }
    }
    return null;
}

/** 幂等发布：tag 已存在但锚点不符即中止，Release / 资产已存在即跳过。 */
function rl_publish(array $p, string $zip): void
{
    $tag = $p['tag'];
    $cur = (string) rl_retry('查询 tag', static fn() => rl_gh_get("git/ref/tags/{$tag}", '.object.sha'));
    if ($cur !== '' && $cur !== $p['sha']) {
        throw new RuntimeException("tag {$tag} 已存在但指向 {$cur}，预期 {$p['sha']}，中止");
    }
    if ($cur === '') {
        rl_retry('创建 tag', static fn() => rl_gh_post('git/refs',
            ['ref' => "refs/tags/{$tag}", 'sha' => $p['sha']], '.object.sha'));
        echo "  tag 已创建：{$tag} → " . substr($p['sha'], 0, 7) . "\n";
    } else {
        echo "  tag 已存在且锚定正确，跳过\n";
    }

    $rid = (string) rl_retry('查询 Release', static fn() => rl_gh_get("releases/tags/{$tag}", '.id'));
    if ($rid === '') {
        $body = strtr((string) file_get_contents(rl_tpl() . '/body.md.stub'), rl_vars($p));
        rl_retry('创建 Release', static fn() => rl_gh_post('releases', [
            'tag_name' => $tag, 'name' => 'Mini Blog ' . $tag, 'body' => $body,
            'draft' => false, 'prerelease' => false,
        ], '.id'));
        echo "  Release 已创建：Mini Blog {$tag}\n";
    } else {
        echo "  Release 已存在（id={$rid}），跳过\n";
    }

    $names = (string) rl_retry('查询资产', static fn() => rl_gh_get("releases/tags/{$tag}", '[.assets[].name] | join(",")'));
    if (in_array($p['asset'], explode(',', $names), true)) {
        echo "  资产已存在，跳过：{$p['asset']}\n";
        return;
    }
    rl_retry('上传资产', static function () use ($tag, $zip, $p): bool {
        $out = [];
        exec('gh release upload ' . escapeshellarg($tag) . ' ' . escapeshellarg($zip)
            . ' -R ' . escapeshellarg(rl_repo()) . ' --clobber 2>&1', $out, $rc);
        if ($rc !== 0) throw new RuntimeException(implode(' ', $out));
        return true;
    });
    echo "  资产已上传：{$p['asset']}\n";
}

/** 只读核验：本地锚点恒查，--local 时跳过 GitHub 与匿名下载。 */
function rl_verify(array $p, bool $local): array
{
    $checks = [];
    $add = static function (string $label, bool $ok) use (&$checks): void {
        $checks[] = ['label' => $label, 'ok' => $ok];
    };
    $v = json_decode((string) file_get_contents(rl_root() . '/core/version.json'), true);
    $add('core/version.json 版本', ($v['version'] ?? '') === $p['version']);
    $add('README 版本徽章', str_contains((string) file_get_contents(rl_root() . '/README.md'), 'version-' . $p['version']));
    $reg = json_decode((string) file_get_contents(rl_root() . '/api/registry.json'), true);
    $add('registry.json core_version', ($reg['core_version'] ?? '') === $p['version']);
    $ru = (string) file_get_contents(rl_root() . '/tools/remote-update.php');
    $add('演练线 V1 = 上一版', rl_const($ru, 'V1') === $p['prev']);
    $add('演练线 V2 = 当前版', rl_const($ru, 'V2') === $p['version']);
    $add('演练线存在唯一载荷断言', count(rl_payload_of($ru)) === 1);
    foreach (['plugin.json.stub', 'marker.txt.stub', 'DemoPlugin.php.stub',
              'CHANGELOG.md.stub', 'RELEASE.md.stub', 'body.md.stub'] as $t) {
        $add('模板齐全 ' . $t, is_file(rl_tpl() . '/' . $t));
    }
    if ($local) return $checks;

    $sha = (string) rl_retry('核验 tag', static fn() => rl_gh_get("git/ref/tags/{$p['tag']}", '.object.sha'));
    // tag 锚点的正确性由 publish 严格校验（已存在但指错即中止）；发版后还会有后续提交，
    // 这里只核存在性，不拿 HEAD 与 tag 做相等断言。
    $add('tag 存在且可读', $sha !== '');
    $assets = (string) rl_retry('核验资产', static fn() => rl_gh_get("releases/tags/{$p['tag']}", '[.assets[].name] | join(",")'));
    $add('Release 资产存在', in_array($p['asset'], explode(',', $assets), true));

    $url = 'https://github.com/' . $p['repo'] . '/releases/download/' . $p['tag'] . '/' . $p['asset'];
    $tmp = sys_get_temp_dir() . '/rl-verify-' . $p['version'] . '.zip';
    $done = false;
    for ($i = 0; $i < 5 && !$done; $i++) {
        $o = [];
        exec('curl -fsSL --retry 6 --retry-all-errors --retry-delay 2 --connect-timeout 20 '
            . escapeshellarg($url) . ' -o ' . escapeshellarg($tmp) . ' 2>&1', $o, $rc);
        if ($rc === 0 && is_file($tmp) && filesize($tmp) > 0) {
            exec('unzip -tqq ' . escapeshellarg($tmp), $t, $trc);
            $done = $trc === 0;
        }
        if (!$done) { @unlink($tmp); sleep(2); }
    }
    $add('匿名下载 zip 完整', $done);
    if (!$done) return $checks;

    $dir = sys_get_temp_dir() . '/rl-verify-' . $p['version'];
    exec('rm -rf ' . escapeshellarg($dir) . ' && mkdir -p ' . escapeshellarg($dir));
    exec('unzip -q -o ' . escapeshellarg($tmp) . ' -d ' . escapeshellarg($dir), $o2, $rc2);
    $b = $dir . '/Mini_Blog';
    $meta = json_decode((string) @file_get_contents($b . '/plugin.json'), true);
    $add('包内 plugin.json 版本', ($meta['version'] ?? '') === $p['version']);
    $add('包内 marker 带版本', str_contains((string) @file_get_contents($b . '/marker.txt'), 'v' . $p['version']));
    $add('包内类常量版本', str_contains((string) @file_get_contents($b . '/DemoPlugin.php'), "const VERSION = '{$p['version']}'"));
    $add('增量载荷随包发布', is_file($b . '/' . $p['payload']));
    $add('清单与签名齐全', is_file($b . '/manifest.sha256') && is_file($b . '/signature.json'));
    return $checks;
}

function rl_report(array $checks): int
{
    $fail = 0;
    foreach ($checks as $c) {
        echo ($c['ok'] ? '  PASS  ' : '  FAIL  ') . $c['label'] . "\n";
        if (!$c['ok']) $fail++;
    }
    echo "=== verify：" . (count($checks) - $fail) . " passed, {$fail} failed ===\n";
    return $fail === 0 ? 0 : 1;
}
