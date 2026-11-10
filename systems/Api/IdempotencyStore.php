<?php
declare(strict_types=1);
namespace Wx\Systems\Api;

use RuntimeException;

/**
 * Idempotency-Key 服务端去重（借鉴 Stripe：校验失败不入缓存）。
 * 纯文件 JSON 存储，无 Redis / 无第三方依赖；key 经 SHA-256 后作文件名，天然防路径穿越。
 *
 * v0.20 从「查后写」升级为「先占位、后回填」：
 * 原实现 get 未命中就执行、执行完再 put，两个并发的同键请求会各自执行一遍 ——
 * 幂等键在最需要它的时候（客户端重试风暴）恰好失效。现在 claim() 在同一把排他锁内
 * 完成「读 → 判定 → 写 pending」，只有第一个请求拿到 claimed，其余看到 inflight。
 * pending 占位自带短 TTL（30s），进程崩溃不会让同键永久不可用。
 */
final class IdempotencyStore
{
    private const PENDING_TTL = 30;

    private string $dir;

    public function __construct(private string $root, private int $ttl = 3600)
    {
        $this->dir = $root . '/storage/api/idempotency';
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new RuntimeException('无法创建幂等缓存目录：' . $this->dir);
        }
    }

    /**
     * 原子占位。同键并发只有一个能拿到 claimed，其余看到 inflight / conflict。
     *
     * @return array{state: 'claimed'|'replay'|'inflight'|'conflict'|'degraded', status?: int, body?: array<string,mixed>}
     */
    public function claim(string $key, string $fingerprint): array
    {
        $fh = @fopen($this->file($key), 'c+');
        if ($fh === false || !flock($fh, LOCK_EX)) {
            if (is_resource($fh)) fclose($fh);
            // 存储不可用：退化为「不幂等」，与未开启该能力一致，但状态可见。
            return ['state' => 'degraded'];
        }

        try {
            $data = $this->readLocked($fh);
            if ($data === [] || (int) ($data['expires_at'] ?? 0) < time()) {
                $this->writeLocked($fh, [
                    'state'       => 'pending',
                    'fingerprint' => $fingerprint,
                    'expires_at'  => time() + self::PENDING_TTL,
                ]);
                return ['state' => 'claimed'];
            }
            if ((string) ($data['fingerprint'] ?? '') !== $fingerprint) {
                return ['state' => 'conflict'];
            }
            if ((string) ($data['state'] ?? 'done') === 'pending') {
                return ['state' => 'inflight'];
            }
            return [
                'state'  => 'replay',
                'status' => (int) ($data['status'] ?? 200),
                'body'   => (array) ($data['body'] ?? []),
            ];
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /** 回填成功结果；fingerprint 校验失败说明占位已被别的键复用，直接放弃写入。 */
    public function complete(string $key, string $fingerprint, int $status, array $body): void
    {
        $fh = @fopen($this->file($key), 'c+');
        if ($fh === false || !flock($fh, LOCK_EX)) {
            if (is_resource($fh)) fclose($fh);
            return;
        }
        try {
            $data = $this->readLocked($fh);
            if ($data !== [] && (string) ($data['fingerprint'] ?? '') !== $fingerprint) return;
            $this->writeLocked($fh, [
                'state'       => 'done',
                'fingerprint' => $fingerprint,
                'status'      => $status,
                'body'        => $body,
                'expires_at'  => time() + max(60, $this->ttl),
            ]);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /** 执行失败时释放占位，让客户端可以重试；故障不固化。 */
    public function release(string $key): void
    {
        @unlink($this->file($key));
    }

    /** @return array<string, mixed>|null 只读视图，供运维 / 测试核对，不参与写入判定。 */
    public function get(string $key): ?array
    {
        $fh = @fopen($this->file($key), 'r');
        if ($fh === false) return null;
        try {
            if (!flock($fh, LOCK_SH)) return null;
            $data = $this->readLocked($fh);
            if ($data === [] || (int) ($data['expires_at'] ?? 0) < time()) return null;
            return $data;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /** @return array<string, mixed> */
    private function readLocked($fh): array
    {
        rewind($fh);
        $raw = (string) stream_get_contents($fh);
        if ($raw === '') return [];
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $data */
    private function writeLocked($fh, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) return;
        if (ftruncate($fh, 0) === false) return;
        rewind($fh);
        fwrite($fh, $json);
        fflush($fh);
    }

    private function file(string $key): string
    {
        return $this->dir . '/' . hash('sha256', $key) . '.json';
    }
}
