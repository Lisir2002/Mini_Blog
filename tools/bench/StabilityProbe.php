<?php
declare(strict_types=1);
namespace Wx\Tools\Bench;

use Wx\Systems\Api\IdempotencyStore;
use Wx\Systems\Api\RateLimiter;
use Wx\Systems\Lifecycle\Fs;

/**
 * 稳定性的代价 —— v0.20 用正确性换来的开销，必须能被量化，而不是只说「更安全了」。
 *
 * 三组对照，口径都刻意贴近真实请求：
 *   1. 幂等占位：claim + complete 一次（每请求一次），给出 µs/次；
 *   2. 限流：同一键连续 check（最坏争用），给出 µs/次；
 *   3. 流式下载：同一文件分别走 setBody（全量）与 setStream（64KB 分块），
 *      在独立子进程里测峰值内存与写出字节数——内存不隔离就会被父进程污染。
 *
 * 只报告实测值，不设「应该多快」的阈值；阈值属于守卫，不属于基准。
 */
final class StabilityProbe
{
    private const CHUNK = 65536;

    /** @return array<string, mixed> */
    public function run(int $ops = 2000, int $fileMb = 64): array
    {
        $root = sys_get_temp_dir() . '/wx-bench-' . bin2hex(random_bytes(4));
        mkdir($root, 0775, true);

        try {
            return [
                'idempotency' => $this->idempotency($root, $ops),
                'rate_limit'  => $this->rateLimit($root, $ops),
                'concurrency' => $this->concurrency($root),
                'streaming'   => $this->streaming($root, $fileMb),
            ];
        } finally {
            Fs::removeDir($root);
        }
    }

    /** @return array{ops:int, us_per_op:float, total_ms:float} */
    private function idempotency(string $root, int $ops): array
    {
        $store = new IdempotencyStore($root);
        $start = hrtime(true);
        for ($i = 0; $i < $ops; $i++) {
            $key = 'bench-' . $i;
            $fp = 'fp-' . $i;
            $store->claim($key, $fp);
            $store->complete($key, $fp, 200, ['ok' => true]);
        }
        $ms = (hrtime(true) - $start) / 1_000_000.0;
        return ['ops' => $ops, 'us_per_op' => $ms * 1000 / $ops, 'total_ms' => $ms];
    }

    /** @return array{ops:int, us_per_op:float, total_ms:float} */
    private function rateLimit(string $root, int $ops): array
    {
        // 阈值放大到不可能触发，只测「读-改-写」本身，不掺 429 分支。
        $limiter = new RateLimiter($root, 1_000_000, 60);
        $start = hrtime(true);
        for ($i = 0; $i < $ops; $i++) {
            $limiter->check('bench-key');
        }
        $ms = (hrtime(true) - $start) / 1_000_000.0;
        return ['ops' => $ops, 'us_per_op' => $ms * 1000 / $ops, 'total_ms' => $ms];
    }

    /**
     * 多进程同时占位：验证「锁换来的是不丢条目」，并给出争用下的墙钟吞吐。
     * @return array{workers:int, ops_each:int, expected:int, stored:int, done:int, wall_ms:float}
     */
    private function concurrency(string $root, int $workers = 6, int $ops = 200): array
    {
        $expected = $workers * $ops;
        if (!function_exists('pcntl_fork')) {
            return ['workers' => 0, 'ops_each' => 0, 'expected' => 0, 'stored' => 0,
                    'done' => 0, 'wall_ms' => 0.0];
        }

        $pids = [];
        $start = hrtime(true);
        for ($w = 0; $w < $workers; $w++) {
            $pid = pcntl_fork();
            if ($pid === -1) break;
            if ($pid === 0) {
                $store = new IdempotencyStore($root);
                for ($i = 0; $i < $ops; $i++) {
                    $key = sprintf('cc-%d-%d', $w, $i);
                    $fp = 'fp-' . $key;
                    if (($store->claim($key, $fp)['state'] ?? '') === 'claimed') {
                        $store->complete($key, $fp, 200, ['ok' => true]);
                    }
                }
                // 子进程不继承父进程的输出：exit 会 flush 缓冲，可能把父进程的输出重复一遍。
                fclose(STDOUT);
                exit(0);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) pcntl_waitpid($pid, $status);
        $wall = (hrtime(true) - $start) / 1_000_000.0;

        $stored = 0;
        $done = 0;
        $store = new IdempotencyStore($root);
        for ($w = 0; $w < $workers; $w++) {
            for ($i = 0; $i < $ops; $i++) {
                $row = $store->get(sprintf('cc-%d-%d', $w, $i));
                if ($row === null) continue;
                $stored++;
                if (($row['state'] ?? '') === 'done') $done++;
            }
        }
        return ['workers' => count($pids), 'ops_each' => $ops, 'expected' => $expected,
                'stored' => $stored, 'done' => $done, 'wall_ms' => $wall];
    }

    /**
     * @return array{file_mb:int, body:array<string,mixed>, stream:array<string,mixed>}
     */
    private function streaming(string $root, int $fileMb): array
    {
        $file = $root . '/payload.bin';
        $size = $fileMb * 1024 * 1024;
        $h = fopen($file, 'wb');
        fseek($h, $size - 1);
        fwrite($h, 'x');            // 稀疏文件：不占实际磁盘，读取仍是真实 64MB
        fclose($h);

        return [
            'file_mb' => $fileMb,
            'body'    => $this->measure($root, $file, 'body'),
            'stream'  => $this->measure($root, $file, 'stream'),
        ];
    }

    /**
     * 在独立进程里跑一次 send()：内存峰值必须是子进程自己的，不能用父进程的。
     * @return array{ms:float, peak_mb:float, bytes:int}
     */
    private function measure(string $root, string $file, string $mode): array
    {
        $script = $root . '/send-' . $mode . '.php';
        file_put_contents($script, $this->script());

        // stdout 必须指向真实文件而不是 /dev/null：写 /dev/null 会被内核直接丢弃，
        // 两种模式的耗时都会变成 0，等于没测。落盘到同一目录，口径一致。
        $sink = $root . '/out-' . $mode . '.bin';
        $cmd = [PHP_BINARY, '-d', 'display_errors=0', $script, dirname(__DIR__, 2), $file, $mode];
        $proc = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['file', $sink, 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($proc)) return ['ms' => 0.0, 'peak_mb' => 0.0, 'bytes' => 0];

        fclose($pipes[0]);
        $raw = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($proc);
        @unlink($script);

        $out = json_decode($raw, true);
        $written = is_file($sink) ? (int) filesize($sink) : 0;
        @unlink($sink);
        return [
            'ms'      => (float) ($out['ms'] ?? 0),
            'peak_mb' => (float) ($out['peak'] ?? 0) / 1048576,
            'bytes'   => (int) ($out['bytes'] ?? 0),
            'written' => $written,
        ];
    }

    /** 子进程脚本：两种输出方式共用同一份文件与同一套计时。 */
    private function script(): string
    {
        return '<?php
$root = require $argv[1] . "/core/bootstrap.php";
$file = $argv[2];
$chunk = ' . self::CHUNK . ';
$r = new \Wx\Core\Http\Response(200, ["Content-Type" => "application/octet-stream"]);
$bytes = 0;
if ($argv[3] === "body") {
    $data = (string) file_get_contents($file);
    $bytes = strlen($data);
    $r->setBody($data);
} else {
    $r->setStream(static function () use ($file, $chunk, &$bytes) {
        return (static function () use ($file, $chunk, &$bytes) {
            $h = fopen($file, "rb");
            while (!feof($h)) {
                $part = fread($h, $chunk);
                if ($part === false || $part === "") break;
                $bytes += strlen($part);
                yield $part;
            }
            fclose($h);
        })();
    });
}
$t = hrtime(true);
$r->send();
fwrite(STDERR, json_encode(["ms" => (hrtime(true) - $t) / 1e6,
    "peak" => memory_get_peak_usage(true), "bytes" => $bytes]));
';
    }
}
