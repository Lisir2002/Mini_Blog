<?php
declare(strict_types=1);
namespace Wx\Systems\Permission;

/**
 * 文件后端 —— 让权限基础数据的缓存跨请求、跨同机进程共享。
 *
 * 失效用「纪元文件」而不是逐条删除：clear() 只是写入新的随机纪元，
 * 旧条目在读取时因纪元不匹配被自然忽略。这样多进程并发下不会出现
 * 「删到一半被写回」的竞态，也不需要广播。
 *
 * 诚实的边界：共享范围是同一台主机上的同一目录；跨主机需换成共享存储
 * （文档已列入 v1.0），但本类之外的代码一行不用改。
 */
final class FileCacheBackend implements CacheBackend
{
    public function __construct(private string $dir)
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }

    public function role(): string { return 'file_cache_backend'; }

    public function epoch(): string
    {
        $file = $this->dir . '/.epoch';
        if (!is_file($file)) {
            @file_put_contents($file, bin2hex(random_bytes(8)), LOCK_EX);
        }
        $value = trim((string) @file_get_contents($file));
        return $value !== '' ? $value : 'unreadable';
    }

    public function get(string $key, string $epoch): mixed
    {
        $file = $this->path($key);
        if (!is_file($file)) return false;
        $decoded = json_decode((string) @file_get_contents($file), true);
        if (!is_array($decoded) || (string) ($decoded['epoch'] ?? '') !== $epoch) return false;
        return $decoded['value'] ?? false;
    }

    public function set(string $key, mixed $value, string $epoch): void
    {
        // 纪元在写入瞬间可能已被别的进程推进：再读一次，避免把旧纪元的值写回去
        if ($this->epoch() !== $epoch) return;
        $payload = json_encode(
            ['epoch' => $epoch, 'value' => $value],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($payload === false) return;
        @file_put_contents($this->path($key), $payload, LOCK_EX);
    }

    public function clear(): void
    {
        @file_put_contents($this->dir . '/.epoch', bin2hex(random_bytes(8)), LOCK_EX);
    }

    public function describe(): array
    {
        return ['driver' => 'file', 'dir' => $this->dir];
    }

    private function path(string $key): string
    {
        return $this->dir . '/' . hash('sha256', $key) . '.json';
    }
}
