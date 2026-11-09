<?php
declare(strict_types=1);
namespace Wx\Systems\Api;

use RuntimeException;

/** 固定窗口限流（429 + Retry-After + X-RateLimit-* 头）。纯文件存储，无第三方依赖。 */
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

    /** @return array{allowed: bool, limit: int, remaining: int, reset: int, retry_after: int} */
    public function check(string $key): array
    {
        $now  = time();
        $file = $this->dir . '/' . hash('sha256', $key) . '.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
        $data = is_array($data) ? $data : [];
        $start = (int) ($data['window_start'] ?? 0);
        $count = (int) ($data['count'] ?? 0);
        if ($start === 0 || $now - $start >= max(1, $this->window)) {
            $start = $now;
            $count = 0;
        }
        $count++;
        file_put_contents($file, json_encode(['window_start' => $start, 'count' => $count]));
        $reset = $start + max(1, $this->window);
        return [
            'allowed'     => $count <= $this->max,
            'limit'       => $this->max,
            'remaining'   => max(0, $this->max - $count),
            'reset'       => $reset,
            'retry_after' => max(1, $reset - $now),
        ];
    }
}
