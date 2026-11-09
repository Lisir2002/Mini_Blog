<?php
declare(strict_types=1);
namespace Wx\Systems\Permission;

/**
 * 进程内后端 —— 与 v0.7 行为完全一致，只在单次请求内有效。
 * 作为「关闭共享缓存」时的显式选项，也让守卫可以对照两种后端的结果是否逐项一致。
 */
final class ArrayCacheBackend implements CacheBackend
{
    /** @var array<string, mixed> */
    private array $entries = [];
    private string $epoch;

    public function __construct()
    {
        $this->epoch = $this->newEpoch();
    }

    public function role(): string { return 'array_cache_backend'; }

    public function epoch(): string { return $this->epoch; }

    public function get(string $key, string $epoch): mixed
    {
        if ($epoch !== $this->epoch) return false;
        return array_key_exists($key, $this->entries) ? $this->entries[$key] : false;
    }

    public function set(string $key, mixed $value, string $epoch): void
    {
        if ($epoch !== $this->epoch) return;
        $this->entries[$key] = $value;
    }

    public function clear(): void
    {
        $this->entries = [];
        $this->epoch = $this->newEpoch();
    }

    public function describe(): array
    {
        return ['driver' => 'memory', 'entries' => count($this->entries)];
    }

    private function newEpoch(): string
    {
        return bin2hex(random_bytes(8));
    }
}
