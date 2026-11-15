<?php
declare(strict_types=1);
namespace Wx\Core;

use RuntimeException;
use Throwable;

/**
 * 事件总线 —— 「万象皆接口」的落点之二。
 *
 * 核心只声明内核级钩子（kernel.* / route.* / system.*）；
 * 业务钩子由各系统层自行声明，核心不知道它们的存在。
 * action 广播事件（无返回），filter 传递并改写值（链式）。
 *
 * v0.67 A1 收口：
 *   - emit 的监听者异常默认隔离：一个坏插件不再截断后面所有监听者；
 *     异常不静默吞掉，errors() 可查，并经 kernel.trace 留日志。strict(true) 恢复旧语义。
 *   - apply 保持严格：filter 承载裁决语义，中途违约必须抛，绝不隔离出假裁决。
 *   - on/filter/once 返回句柄，off/clear 可解绑；expect 声明 filter 返回值契约。
 */
final class HookBus
{
    private array $actions   = [];
    private array $filters   = [];
    private array $expect    = [];
    private array $errors    = [];
    private int   $seq       = 0;
    private bool  $isolate   = true;
    private bool  $reporting = false;
    /** 控制流型 action：异常必须冒泡到内核异常边界，不能被隔离吞掉。 */
    private array $strictTags = [];

    public function on(string $tag, callable $cb, int $priority = 10): int
    { return $this->add('actions', $tag, $cb, $priority, false); }

    public function filter(string $tag, callable $cb, int $priority = 10): int
    { return $this->add('filters', $tag, $cb, $priority, false); }

    /** 一次性广播监听者：触发一次即自动解绑，避免长生命周期内核里越积越多。 */
    public function once(string $tag, callable $cb, int $priority = 10): int
    { return $this->add('actions', $tag, $cb, $priority, true); }

    /** 按句柄解绑；返回是否真的移除了一个监听者。 */
    public function off(string $tag, int $id): bool
    {
        foreach (['actions', 'filters'] as $bucket) {
            foreach ($this->{$bucket}[$tag] ?? [] as $p => $list) {
                if (!isset($list[$id])) continue;
                unset($this->{$bucket}[$tag][$p][$id]);
                return true;
            }
        }
        return false;
    }

    /** 清空某个 tag 的全部监听者（整包卸载时用），返回移除数量。 */
    public function clear(string $tag): int
    {
        $n = 0;
        foreach (['actions', 'filters'] as $bucket) {
            foreach ($this->{$bucket}[$tag] ?? [] as $list) $n += count($list);
            unset($this->{$bucket}[$tag]);
        }
        return $n;
    }

    /** @return list<array{kind:string, priority:int, id:int, once:bool}> */
    public function listeners(string $tag): array
    {
        $out = [];
        foreach (['action' => 'actions', 'filter' => 'filters'] as $kind => $bucket) {
            foreach ($this->{$bucket}[$tag] ?? [] as $priority => $list) {
                foreach ($list as $id => $entry) {
                    $out[] = ['kind' => $kind, 'priority' => $priority,
                              'id' => $id, 'once' => $entry['once']];
                }
            }
        }
        return $out;
    }

    /** 被隔离的异常留痕：不静默吞掉，可查询、可追溯。 */
    public function errors(): array { return $this->errors; }

    /** strict(true) 恢复旧语义：action 异常照常冒泡，不做隔离。 */
    public function strict(bool $on = true): static { $this->isolate = !$on; return $this; }

    /** 声明某个 action 恒为严格：route.* 这类控制流不是通知广播，异常要交给异常边界裁决。 */
    public function strictTag(string $tag): void { $this->strictTags[$tag] = true; }

    /** 声明某 filter 的返回值契约；未声明的 tag 保持零校验（向后兼容）。 */
    public function expect(string $tag, string $type): void { $this->expect[$tag] = $type; }

    public function emit(string $tag, mixed ...$args): void
    {
        $hard = !$this->isolate || isset($this->strictTags[$tag]);
        foreach ($this->sorted($this->actions[$tag] ?? []) as $l) {
            if ($l['once']) $this->off($tag, $l['id']);
            if ($hard) { ($l['cb'])(...$args); continue; }
            try { ($l['cb'])(...$args); } catch (Throwable $e) { $this->record($tag, $e); }
        }
    }

    public function apply(string $tag, mixed $value, mixed ...$args): mixed
    {
        $type = $this->expect[$tag] ?? null;
        foreach ($this->sorted($this->filters[$tag] ?? []) as $l) {
            if ($l['once']) $this->off($tag, $l['id']);
            $value = ($l['cb'])($value, ...$args);
            if ($type !== null) $this->assertType($tag, $type, $value);
        }
        return $value;
    }

    public function has(string $tag): bool
    { return isset($this->actions[$tag]) || isset($this->filters[$tag]); }

    public function tags(): array
    { return ['actions' => array_keys($this->actions), 'filters' => array_keys($this->filters)]; }

    /** 失败经日志系统已有的 kernel.trace 出口留痕；reporting 防止留痕本身再触发递归。 */
    private function record(string $tag, Throwable $e): void
    {
        $this->errors[] = ['tag' => $tag, 'error' => $e::class, 'message' => $e->getMessage()];
        if ($this->reporting) return;
        $this->reporting = true;
        try {
            $this->emit('kernel.trace', '钩子监听者失败已隔离', [
                'tag' => $tag, 'error' => $e::class, 'message' => $e->getMessage(),
            ]);
        } finally { $this->reporting = false; }
    }

    private function assertType(string $tag, string $type, mixed $v): void
    {
        $ok = match ($type) {
            'bool' => is_bool($v), 'int' => is_int($v), 'string' => is_string($v),
            'array' => is_array($v), 'float' => is_float($v),
            default => $v instanceof $type,
        };
        if (!$ok) throw new RuntimeException(
            "钩子 {$tag} 的 filter 返回值违反契约：应为 {$type}，实际 " . get_debug_type($v)
        );
    }

    /** 按 priority 升序；同优先级内按注册顺序。返回带句柄的条目，供 once/off 使用。 */
    private function sorted(array $buckets): array
    {
        ksort($buckets);
        $out = [];
        foreach ($buckets as $bucket) {
            foreach ($bucket as $id => $entry) $out[] = ['id' => $id] + $entry;
        }
        return $out;
    }

    private function add(string $bucket, string $tag, callable $cb, int $priority, bool $once): int
    {
        $id = $this->seq++;
        $this->{$bucket}[$tag][$priority][$id] = ['cb' => $cb, 'once' => $once];
        return $id;
    }
}
