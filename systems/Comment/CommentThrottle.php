<?php
declare(strict_types=1);
namespace Wx\Systems\Comment;

use RuntimeException;
use Wx\Core\Kernel;
use Wx\Systems\Api\RateLimiter;

/**
 * 评论写入节流 —— 按「互动主体」而不是全站 IP 计数。
 *
 * 直接复用 systems/Api/RateLimiter：它的 flock 并发正确性在 v0.20 已经验过，
 * 没必要在评论层重写一份。key 带 'comment:' 前缀，与 API 全局限流的 IP key
 * 分属不同桶，两者互不挤占额度。
 *
 * 限流器故障（目录不可写 / 锁失败）绝不假装「已限流」，也绝不静默放行：
 * 写 error_log 留痕后放行，让值班能从日志看到「这段时间节流是失效的」。
 */
final class CommentThrottle
{
    private ?RateLimiter $limiter = null;
    private bool $unavailable = false;

    public function __construct(
        string $root,
        private int $max = 8,
        private int $window = 60,
        private bool $enabled = true
    ) {
        if (!$this->enabled) return;
        try {
            // 与 API 全局限流共用存储目录即可：计数文件名是 hash(key)，
            // 而这里所有 key 都带 'comment:' 前缀，天然不会撞上纯 IP 的桶。
            $this->limiter = new RateLimiter($root, $this->max, $this->window);
        } catch (RuntimeException $e) {
            $this->unavailable = true;
            error_log('wx: comment throttle unavailable: ' . $e->getMessage());
        }
    }

    public static function fromKernel(Kernel $kernel): self
    {
        return new self(
            $kernel->root(),
            (int) $kernel->config()->get('comment.throttle.max', 8),
            (int) $kernel->config()->get('comment.throttle.window', 60),
            $kernel->config()->get('comment.throttle.enabled', true) === true
        );
    }

    /**
     * @return array{allowed: bool, remaining: int, retry_after: int, degraded: bool}
     */
    public function check(string $actorKey): array
    {
        if (!$this->enabled) {
            return ['allowed' => true, 'remaining' => $this->max, 'retry_after' => 0, 'degraded' => false];
        }
        if ($this->limiter === null || $this->unavailable) {
            return ['allowed' => true, 'remaining' => 0, 'retry_after' => 0, 'degraded' => true];
        }
        $r = $this->limiter->check('comment:' . $actorKey);
        if ($r['degraded']) error_log('wx: comment throttle degraded, request allowed without counting');
        return [
            'allowed'     => $r['allowed'],
            'remaining'   => $r['remaining'],
            'retry_after' => $r['retry_after'],
            'degraded'    => $r['degraded'],
        ];
    }

    public function enabled(): bool { return $this->enabled; }
}
