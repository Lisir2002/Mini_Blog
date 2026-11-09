<?php
declare(strict_types=1);
namespace Wx\Tools\Bench;

/**
 * 部署层 A/B —— 同一份真实项目代码，只改服务器配置，测真实墙钟时间。
 *
 * 两组对照分别回答：
 *   1. opcache：字节码缓存开 / 关，对长驻式进程内连续请求的影响；
 *   2. CPU 可用核数：4 个并发 worker 被限制在 1 / 4 / 全部可见核上的吞吐差异。
 * 不启动 HTTP 服务器、不依赖数据库；worker 走内存回退，任何人都能原样重跑。
 */
final class DeployProbe
{
    private string $php;
    private string $script;

    public function __construct(private string $root)
    {
        $this->php = escapeshellarg(PHP_BINARY);
        $this->script = escapeshellarg($root . '/tools/bench.php');
    }

    public function available(): bool
    {
        return function_exists('shell_exec') && trim((string) @shell_exec('command -v taskset')) !== '';
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        if (!$this->available()) return ['skipped' => true];

        $opcache = [];
        foreach ([0, 1] as $flag) {
            $cmd = $this->php . ' -d opcache.enable_cli=' . $flag . ' ' . $this->script . ' worker 200';
            // 5 次采样：本机 CLI 模型下这项差异落在噪声内，多跑几次才看得出来。
            $opcache[$flag ? 'on' : 'off'] = $this->median(5, $cmd, true);
        }

        $cpu = [];
        foreach (['0', '0-3', '0-31'] as $set) {
            $inner = '';
            for ($i = 0; $i < 4; $i++) {
                $inner .= $this->php . ' -d opcache.enable_cli=0 ' . $this->script . ' worker 80 >/dev/null 2>&1 & ';
            }
            $inner .= 'wait';
            $cmd = 'taskset -c ' . escapeshellarg($set) . ' sh -c ' . escapeshellarg($inner);
            $cpu[$set] = $this->median(2, $cmd, false);
        }

        return [
            'skipped' => false,
            'opcache' => $opcache,
            'cpu'     => $cpu,
            'env'     => [
                'php'        => PHP_VERSION,
                'visible'    => trim((string) @shell_exec('nproc')),
                'cpu_max'    => trim((string) @file_get_contents('/sys/fs/cgroup/cpu.max')),
                'iterations' => 'opcache 200 req/进程；cpu 4 并发 × 80 req',
            ],
        ];
    }

    /** 真跑 N 次取中位数；$parse 时读 worker 自报的内部耗时，否则读整条命令的墙钟。 */
    private function median(int $runs, string $cmd, bool $parse): float
    {
        $samples = [];
        for ($i = 0; $i < $runs; $i++) {
            $start = hrtime(true);
            $out = (string) @shell_exec($cmd);
            $wall = (hrtime(true) - $start) / 1_000_000.0;
            $samples[] = $parse && preg_match('/[\d.]+/', trim($out), $m) === 1 ? (float) $m[0] : $wall;
        }
        sort($samples);
        return round($samples[intdiv(count($samples), 2)], 2);
    }
}
