<?php
declare(strict_types=1);
namespace Wx\Systems\Api;

use RuntimeException;

/**
 * Idempotency-Key 服务端去重（借鉴 Stripe：校验失败不入缓存）。
 * 纯文件 JSON 存储，无 Redis / 无第三方依赖；key 经 SHA-256 后作文件名，天然防路径穿越。
 */
final class IdempotencyStore
{
    private string $dir;

    public function __construct(private string $root, private int $ttl = 3600)
    {
        $this->dir = $root . '/storage/api/idempotency';
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new RuntimeException('无法创建幂等缓存目录：' . $this->dir);
        }
    }

    /** @return array{fingerprint: string, status: int, body: array<string, mixed>}|null */
    public function get(string $key): ?array
    {
        $file = $this->file($key);
        if (!is_file($file)) return null;
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) return null;
        if ((int) ($data['expires_at'] ?? 0) < time()) {
            @unlink($file);
            return null;
        }
        return $data;
    }

    public function put(string $key, string $fingerprint, int $status, array $body): void
    {
        file_put_contents($this->file($key), json_encode([
            'fingerprint' => $fingerprint,
            'status'      => $status,
            'body'        => $body,
            'expires_at'  => time() + max(60, $this->ttl),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function file(string $key): string
    {
        return $this->dir . '/' . hash('sha256', $key) . '.json';
    }
}
