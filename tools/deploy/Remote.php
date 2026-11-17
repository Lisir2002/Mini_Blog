<?php
declare(strict_types=1);

/**
 * tools/deploy.php 的远程协作者 —— SSH/SCP 执行与输出汇总。
 *
 * 刻意不定义 dp_root / dp_options 等公共函数：它们由入口提供，运行时已定义即可。
 * 零 use 边、零 CLI 分派，避免协作图把工具目录解析成悬空引用 / 双入口。
 *
 * 凭据纪律：SSH 口令经 SSHPASS 环境变量传给 sshpass -e，绝不进命令行参数；
 * 数据库 / 管理员口令写进外置脚本模板后经 stdin 送远程 bash，本地临时文件 0600、
 * 用完即删；本文件与模板都不保存任何真实口令，调用方从环境读取后注入。
 */

/** 把脚本模板注入变量后经 SSH 送远程 bash -s 执行，返回 [退出码, 合并输出]。 */
function dp_run(array $opt, string $stub, array $vars = []): array
{
    $tpl = __DIR__ . '/' . $stub;
    if (!is_file($tpl)) throw new RuntimeException('脚本模板缺失：' . $stub);
    $script = strtr((string) file_get_contents($tpl), $vars + [
        '{{ROOT}}' => $opt['root'], '{{DB}}' => $opt['db'],
        '{{DBUSER}}' => $opt['db_user'], '{{DBPASS}}' => $opt['db_pass'],
        '{{ADMIN}}' => $opt['admin'], '{{ADMINPASS}}' => $opt['admin_pass'],
        '{{THEME}}' => $opt['theme'], '{{DOMAIN}}' => $opt['domain'],
        '{{VERSION}}' => $opt['version'], '{{PORT}}' => (string) $opt['port'],
    ]);
    $tmp = (string) tempnam(sys_get_temp_dir(), 'dp');
    file_put_contents($tmp, $script);
    chmod($tmp, 0600);
    $ssh = 'sshpass -e ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null'
        . ' -o ConnectTimeout=20 -o LogLevel=ERROR -p ' . (int) $opt['port']
        . ' ' . escapeshellarg($opt['user'] . '@' . $opt['host'])
        . " 'bash -s' < " . escapeshellarg($tmp) . ' 2>&1';
    $out = [];
    $rc = 0;
    putenv('SSHPASS=' . $opt['ssh_pass']);
    try { exec($ssh, $out, $rc); } finally { putenv('SSHPASS'); @unlink($tmp); }
    return ['rc' => $rc, 'out' => implode("\n", $out)];
}

/** 上传本地归档到远程指定路径，返回是否成功。 */
function dp_upload(array $opt, string $local, string $remote): bool
{
    $scp = 'sshpass -e scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null'
        . ' -o ConnectTimeout=20 -o LogLevel=ERROR -P ' . (int) $opt['port']
        . ' ' . escapeshellarg($local) . ' '
        . escapeshellarg($opt['user'] . '@' . $opt['host'] . ':' . $remote) . ' 2>&1';
    $out = [];
    $rc = 0;
    putenv('SSHPASS=' . $opt['ssh_pass']);
    try { exec($scp, $out, $rc); } finally { putenv('SSHPASS'); }
    if ($rc !== 0) echo implode("\n", $out) . "\n";
    return $rc === 0;
}

/** 网络抖动重试：把一次远程调用最多跑 n 次，成功即返回。 */
function dp_retry(string $label, callable $fn, int $n = 3): array
{
    for ($i = 1; $i <= $n; $i++) {
        $r = $fn();
        if ($r['rc'] === 0) return $r;
        if ($i === $n) return $r;
        echo "  {$label} 第 {$i} 次失败，重试：\n" . substr($r['out'], -400) . "\n";
        sleep(2);
    }
    return ['rc' => 1, 'out' => 'unreachable'];
}

/** 统一报告一个步骤的远程结果，返回是否通过。 */
function dp_report(string $label, array $r): bool
{
    $ok = $r['rc'] === 0;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    if (!$ok && trim($r['out']) !== '') {
        foreach (explode("\n", trim($r['out'])) as $line) echo '         ' . $line . "\n";
    }
    return $ok;
}

/** 把远程输出里以「CHK 」开头的检查行逐条打印，供 verify 汇总。 */
function dp_checks(string $out): void
{
    foreach (explode("\n", $out) as $line) {
        if (str_starts_with($line, 'CHK ')) echo '        ' . substr($line, 4) . "\n";
    }
}
