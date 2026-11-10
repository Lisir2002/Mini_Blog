<?php
declare(strict_types=1);

use Wx\Tools\Bench\StabilityProbe;

/**
 * 31 · 稳定性的代价 —— 基准必须可重跑，结论必须由数字支撑。
 *
 * v0.20 的原子写与流式下载当时只有正确性守卫，没有代价数字；
 * PERFORMANCE.md §6.4 补上后，这里守两件事：
 *   1. 数字来自真实执行，且换机器不会翻转的结论（内存、条目数）必须成立；
 *   2. 文档必须指向同一条可重跑命令，通过数不得再写死（曾以 250/50 静默过期）。
 *
 * 耗时只打印不断言：它随负载波动，用阈值卡它只会得到假红或假绿。
 */
echo "\n=== 31. 稳定性的代价：原子写与流式下载 ===\n";

require_once $root . '/tools/bench/StabilityProbe.php';

check('基准出厂：bench 有 stability 子命令，文档指向同一命令', function () use ($root): void {
    $cli = (string) file_get_contents($root . '/tools/bench.php');
    truthy(str_contains($cli, "'stability'"), 'bench 未登记 stability 模式');
    truthy(str_contains($cli, 'StabilityProbe'), 'bench 未接入 StabilityProbe');

    $doc = (string) file_get_contents($root . '/docs/PERFORMANCE.md');
    truthy(str_contains($doc, 'php tools/bench.php stability'), '文档缺少可重跑命令');
    truthy(str_contains($doc, '稳定性的代价'), '文档缺少代价章节');
    truthy(str_contains($doc, 'setBody') && str_contains($doc, 'setStream'), '文档未写清两种输出方式');
    truthy(str_contains($doc, 'µs/次'), '文档缺少单次代价');
});

check('实测：并发占位一条不丢，分块内存不随文件增长', function (): void {
    $st = (new StabilityProbe())->run(300, 24);

    $cc = $st['concurrency'];
    truthy($cc['expected'] > 0, '缺少 pcntl，无法验证并发（这是未验证，不是通过）');
    truthy($cc['stored'] === $cc['expected'],
        '并发落盘丢了条目：' . $cc['stored'] . '/' . $cc['expected']);
    truthy($cc['done'] === $cc['expected'],
        '并发回填不完整：' . $cc['done'] . '/' . $cc['expected']);

    $fs = $st['streaming'];
    $bytes = $fs['file_mb'] * 1024 * 1024;
    truthy($fs['body']['written'] === $bytes, '全量写出字节不符：' . $fs['body']['written']);
    truthy($fs['stream']['written'] === $bytes, '分块写出字节不符：' . $fs['stream']['written']);
    truthy($fs['body']['peak_mb'] >= $fs['file_mb'] * 0.9,
        '全量峰值未包含整个文件：' . $fs['body']['peak_mb'] . ' MB');
    truthy($fs['stream']['peak_mb'] <= 16.0, '分块峰值内存过高：' . $fs['stream']['peak_mb'] . ' MB');
    truthy($fs['stream']['peak_mb'] < $fs['body']['peak_mb'] / 4,
        '分块没有显著降低内存：' . $fs['stream']['peak_mb'] . ' vs ' . $fs['body']['peak_mb']);

    printf("         幂等 %.1f µs/次 · 限流 %.1f µs/次 · 并发 %d/%d · 峰值 %.1f → %.1f MB\n",
        $st['idempotency']['us_per_op'], $st['rate_limit']['us_per_op'],
        $cc['stored'], $cc['expected'], $fs['body']['peak_mb'], $fs['stream']['peak_mb']);
});

check('防回归：性能文档已纳入事实对账', function () use ($root): void {
    $rec = (string) file_get_contents($root . '/tools/facts/Reconciler.php');
    truthy(str_contains($rec, "'docs/PERFORMANCE.md'"),
        '性能文档未纳入锚点，写死的通过数会再次静默过期');
});
