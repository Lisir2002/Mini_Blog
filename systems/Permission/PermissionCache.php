<?php
declare(strict_types=1);
namespace Wx\Systems\Permission;

/**
 * 决策路径的缓存 —— 只缓存「基础数据读取」，绝不缓存最终决策。
 *
 * 两层结构：
 *   1. 请求内条目（$entries）：同一次请求里重复询问（例如列表逐篇过滤）走内存；
 *   2. 可选共享后端（CacheBackend）：跨请求 / 跨同机进程复用，用 epoch 做全局失效。
 *
 * 刻意不缓存 decide() 的结果：策略钩子 permission.decide 可能携带请求级条件
 * （例如「工作时间之外禁止发布」），缓存最终结果会让这类收紧策略被绕过。
 * 这里只缓存 grants / acl / parents / ancestors / group_roles 这类纯数据读取，
 * 任何授权写操作（grant / revoke / setAcl / setParent / 组变更）都必须显式 flush。
 *
 * 关闭缓存（enabled=false）或换后端都不改变决策语义，只改变读取路径 —— 这是守卫要证明的事。
 */
final class PermissionCache
{
    /** @var array<string, mixed> */
    private array $entries = [];
    private int $hits = 0;
    private int $misses = 0;
    private int $flushes = 0;
    private string $epoch;

    public function __construct(
        private bool $enabled = true,
        private ?CacheBackend $backend = null
    ) {
        $this->epoch = $backend?->epoch() ?? 'memory';
    }

    public function role(): string { return 'permission_cache'; }

    public function enabled(): bool { return $this->enabled; }

    public function remember(string $key, callable $producer): mixed
    {
        if (!$this->enabled) return $producer();

        // 共享后端下，先对齐 epoch：别的进程 flush 过，就立刻丢弃本进程的旧条目。
        if ($this->backend !== null) {
            $current = $this->backend->epoch();
            if ($current !== $this->epoch) {
                $this->entries = [];
                $this->epoch = $current;
            }
        }

        if (array_key_exists($key, $this->entries)) {
            $this->hits++;
            return $this->entries[$key];
        }
        if ($this->backend !== null) {
            $hit = $this->backend->get($key, $this->epoch);
            if ($hit !== false) {
                $this->hits++;
                return $this->entries[$key] = $hit;
            }
        }
        $this->misses++;
        $value = $producer();
        $this->entries[$key] = $value;
        $this->backend?->set($key, $value, $this->epoch);
        return $value;
    }

    /** 授权数据变更后显式失效；宁可多失效一次，也不允许读到过期授权。 */
    public function flush(): void
    {
        $this->flushes++;
        $this->entries = [];
        if ($this->backend !== null) {
            $this->backend->clear();
            $this->epoch = $this->backend->epoch();
        }
    }

    /** @return array<string, mixed> 供守卫与 permission.decisions 接口对账 */
    public function stats(): array
    {
        return [
            'enabled' => $this->enabled ? 1 : 0,
            'hits'    => $this->hits,
            'misses'  => $this->misses,
            'flushes' => $this->flushes,
            'size'    => count($this->entries),
            'backend' => $this->backend === null
                ? ['driver' => 'memory', 'entries' => count($this->entries)]
                : $this->backend->describe(),
            'epoch'   => $this->epoch,
        ];
    }
}
