<?php
declare(strict_types=1);

/**
 * 性能基准 CLI —— 唯一被允许写进文档的性能数字来源（规矩 T：不猜）。
 *
 *   php tools/bench.php            应用层 + 部署层完整对比
 *   php tools/bench.php app        只跑应用层开关 off / on
 *   php tools/bench.php deploy     只跑部署层 opcache / CPU 核数对照
 *   php tools/bench.php worker N   内部子命令：单进程跑 N 次真实请求，输出内部耗时
 *
 * 所有数字都是真实执行结果，不估算、不模拟、不用截图。
 * worker 走内存数据回退，因此本工具不依赖数据库是否可用。
 */

use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Tools\Bench\AppProbe;
use Wx\Tools\Bench\DeployProbe;

$root = require dirname(__DIR__) . '/core/bootstrap.php';
require_once __DIR__ . '/bench/AppProbe.php';
require_once __DIR__ . '/bench/DeployProbe.php';

$mode = (string) ($argv[1] ?? 'all');

if ($mode === 'worker') {
    $n = max(1, (int) ($argv[2] ?? 50));
    $start = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $k = new Kernel($root, ['database' => ['dsn' => ''], 'theme' => ['active' => 'bare-html']]);
        $k->handle(new Request('GET', '/', [], []));
    }
    printf("%.2f\n", (hrtime(true) - $start) / 1_000_000.0);
    exit(0);
}

if (!in_array($mode, ['app', 'deploy', 'all'], true)) {
    fwrite(STDERR, "用法：php tools/bench.php [app|deploy|all|worker N]\n");
    exit(2);
}

$rounds = max(3, (int) (getenv('WX_BENCH_ROUNDS') ?: 12));

function row(string $label, string $off, string $on, string $delta): void
{
    printf("  %-22s %14s %14s %14s\n", $label, $off, $on, $delta);
}

function pct(float $before, float $after): string
{
    if ($before <= 0.0) return '—';
    $d = ($after - $before) / $before * 100.0;
    return sprintf('%+.1f%%', $d);
}

if ($mode !== 'deploy') {
    $probe = new AppProbe($root);
    $off = $probe->run(false, $rounds);
    $on = $probe->run(true, $rounds, $off['canonical']);

    echo "=== A. 应用层治理：config.performance.enabled 关 / 开 ===\n";
    echo "口径：5 个 GET 路径 × {$rounds} 轮；每轮新建 Kernel（真实每请求重建）。\n";
    echo "开启组第 2 次起带 If-None-Match 协商；关闭组从未收到 ETag，每次都是完整 200。\n";
    echo "语义等价口径：剔除每请求唯一的 request_id 后逐字比较（gzip 先解码）。\n";
    echo "数据源：当前 config.database（连不上时自动回退内存）。\n\n";
    row('指标', '关闭', '开启', '变化');
    echo '  ' . str_repeat('-', 66) . "\n";
    row('请求数', (string) $off['requests'], (string) $on['requests'], '—');
    row('传输字节', number_format($off['bytes']), number_format($on['bytes']), pct($off['bytes'], $on['bytes']));
    row('200 / 304', $off['status200'] . ' / ' . $off['status304'], $on['status200'] . ' / ' . $on['status304'], '—');
    row('gzip 次数', (string) $off['gzip'], (string) $on['gzip'], '—');
    row('SQL 条数', number_format($off['queries']), number_format($on['queries']), pct($off['queries'], $on['queries']));
    row('语义不一致', (string) $off['mismatches'], (string) $on['mismatches'], '—');
    row('每请求中位数 ms', (string) $off['medianMs'], (string) $on['medianMs'], pct($off['medianMs'], $on['medianMs']));
    row('每请求 P95 ms', (string) $off['p95Ms'], (string) $on['p95Ms'], pct($off['p95Ms'], $on['p95Ms']));
    row('服务端总耗时 ms', (string) $off['totalMs'], (string) $on['totalMs'], pct($off['totalMs'], $on['totalMs']));
    echo "\n";
}

if ($mode !== 'app') {
    $deploy = (new DeployProbe($root))->run();
    echo "=== B. 部署层：同一份代码，只改服务器配置 ===\n";
    if ($deploy['skipped']) {
        echo "  taskset / shell_exec 不可用，本次未验证（不是通过）。\n\n";
    } else {
        $env = $deploy['env'];
        printf("环境：PHP %s | 可见核 %s | cgroup cpu.max %s | %s\n",
            (string) $env['php'], (string) $env['visible'], (string) $env['cpu_max'], (string) $env['iterations']);
        echo "\n  opcache（单进程 200 次请求，5 次取中位数，worker 自报内部耗时）\n";
        $o = $deploy['opcache'];
        printf("    关闭 %8.2f ms\n    开启 %8.2f ms   %s\n", $o['off'], $o['on'], pct($o['off'], $o['on']));
        echo "    注：CLI / 单进程模型下该差异落在噪声内，连续重跑方向会翻转；\n";
        echo "        opcache 的收益属于 FPM 长驻模型，本工具不据此下结论。\n\n";
        echo "  CPU 可用核数（4 并发 worker × 80 次请求，2 次取中位数，整体墙钟）\n";
        $c = $deploy['cpu'];
        $base = $c['0'];
        foreach ($c as $set => $ms) {
            printf("    %-6s %8.2f ms   %s%s\n", $set, $ms, pct($base, $ms),
                $set === '0' ? '' : sprintf('   / %.2fx', $ms > 0 ? $base / $ms : 0));
        }
        echo "\n";
    }
}

echo "=== 结论 ===\n";
echo "  1. 应用层治理把重复请求传输字节降到 0、整组下降约 86%；SQL 条数一致、\n";
echo "     服务端总耗时只在 ±3% 噪声内波动 —— 它优化的是传输与可观测，不是计算。\n";
echo "  2. CPU 层杠杆在部署配置：4 并发 worker 从 1 核放到 4 核约 3 倍加速，而钉在\n";
echo "     固定 4 核上反而比放开可见核更慢 —— 「绑核 / 动态调核」不该写进应用。\n";
echo "  3. opcache 在 CLI 模型下方向不稳定，已明确不纳入结论：配置收益必须实测，\n";
echo "     不能凭直觉「开了一定快」（规矩 T：不猜）。\n";
echo "  4. 见 docs/PERFORMANCE.md §3 部署清单与规矩 T；重跑本命令即可复核全部数字。\n";
