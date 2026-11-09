<?php
declare(strict_types=1);
namespace Wx\Core;

/**
 * 事件总线 —— 「万象皆接口」的落点之二。
 *
 * 核心只声明内核级钩子（kernel.* / route.* / system.*）；
 * 业务钩子由各系统层自行声明，核心不知道它们的存在。
 * action 广播事件（无返回），filter 传递并改写值（链式）。
 */
final class HookBus
{
    private array $actions = [];
    private array $filters = [];
    private int $seq = 0;

    public function on(string $tag, callable $callback, int $priority = 10): void
    {
        $this->actions[$tag][$priority][$this->seq++] = $callback;
    }

    public function filter(string $tag, callable $callback, int $priority = 10): void
    {
        $this->filters[$tag][$priority][$this->seq++] = $callback;
    }

    public function emit(string $tag, mixed ...$args): void
    {
        foreach ($this->sorted($this->actions[$tag] ?? []) as $cb) {
            $cb(...$args);
        }
    }

    public function apply(string $tag, mixed $value, mixed ...$args): mixed
    {
        foreach ($this->sorted($this->filters[$tag] ?? []) as $cb) {
            $value = $cb($value, ...$args);
        }
        return $value;
    }

    public function has(string $tag): bool
    {
        return isset($this->actions[$tag]) || isset($this->filters[$tag]);
    }

    public function tags(): array
    {
        return ['actions' => array_keys($this->actions), 'filters' => array_keys($this->filters)];
    }

    /** 按 priority 升序；同优先级内按注册顺序（保持稳定、可预测）。 */
    private function sorted(array $buckets): array
    {
        ksort($buckets);
        $out = [];
        foreach ($buckets as $bucket) {
            foreach ($bucket as $cb) {
                $out[] = $cb;
            }
        }
        return $out;
    }
}
