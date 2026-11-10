<?php
declare(strict_types=1);
namespace Wx\Systems\Api;

use RuntimeException;

/**
 * 固定窗口限流（429 + Retry-After + X-RateLimit-* 头）。纯文件存储，无第三方依赖。
 *
 * v0.20 修正并发正确性：读-改-写必须在同一把排他锁内完成。
 * 原实现的 file_get_contents + file_put_contents 是两个独立操作，并发请求会同时
 * 读到旧计数再各自写回，计数丢失 —— 限流看似生效、实际放行量远超阈值，
 * 这种「安静地失效」比不设限流更危险。存储不可用时返回 degraded 而不是静默放行，
 * 由调用方留痕，不把限流器故障放大成整站不可用。
 */
final class RateLimiter
{
    private string $dir;

    public function __construct(
        private string $root,
        private int $max = 120,
        private int $window = 60
    ) {
        $this->dir = $root . '/storage/api/ratelimit';
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new RuntimeException('无法创建限流目录：' . $this->dir);
        }
    }

    /** @return array{allowed: bool, limit: int, remaining: int, reset: int, retry_after: int, degraded: bool} */
    public function check(string $key): array
    {
        $now  = time();
        $file = $this->dir . '/' . hash('sha256', $key) . '.json';
        $start = $now;
        $count = 0;
        $degraded = false;

        $fh = @fopen($file, 'c+');
        if ($fh === false || !flock($fh, LOCK_EX)) {
            if (is_resource($fh)) fclose($fh);
            // 无法持久化计数：放行但标记降级，绝不假装「已限流」。
            $degraded = true;
            $count = 1;
        } else {
            try {
                $raw  = (string) stream_get_contents($fh);
                $data = $raw === '' ? [] : json_decode($raw, true);
                $data = is_array($data) ? $data : [];

                $start = (int) ($data['window_start'] ?? 0);
                $count = (int) ($data['count'] ?? 0);
                if ($start === 0 || $now - $start >= max(1, $this->window)) {
                    $start = $now;
                    $count = 0;
                }
                $count++;

                $payload = json_encode(['window_start' => $start, 'count' => $count]);
                if ($payload === false || ftruncate($fh, 0) === false) {
                    $degraded = true;
                } else {
                    rewind($fh);
                    if (fwrite($fh, $payload) === false) $degraded = true;
                    fflush($fh);
                }
            } finally {
                flock($fh, LOCK_UN);
                fclose($fh);
            }
        }

        $reset = $start + max(1, $this->window);
        return [
            'allowed'     => $count <= $this->max,
            'limit'       => $this->max,
            'remaining'   => max(0, $this->max - $count),
            'reset'       => $reset,
            'retry_after' => max(1, $reset - $now),
            'degraded'    => $degraded,
        ];
    }
}
