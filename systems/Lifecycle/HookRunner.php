<?php
declare(strict_types=1);
namespace Wx\Systems\Lifecycle;

use Wx\Core\HookBus;

/**
 * 钩子协作者 —— 事件广播与包自带生命周期脚本的执行。
 * 包可用原生 PHP 的 lifecycle.php 返回 on_install / on_upgrade / on_uninstall 闭包。
 */
final class HookRunner
{
    public function __construct(private HookBus $hooks) {}

    public function role(): string { return 'hook_runner'; }

    public function before(string $operation, mixed ...$args): void
    {
        $this->hooks->emit('lifecycle.collab', $this->role(), 'before_' . $operation);
        $this->hooks->emit('lifecycle.before_' . $operation, ...$args);
    }

    /** @param array<string, mixed> $record */
    public function after(string $operation, string $key, array $record): void
    {
        $this->hooks->emit('lifecycle.collab', $this->role(), 'after_' . $operation);
        $this->hooks->emit('lifecycle.after_' . $operation, $key, $record);
        $this->hooks->emit('kernel.trace', '装卸操作完成', ['operation' => $operation, 'unit' => $key]);
    }

    public function failed(string $operation, string $key, string $reason): void
    {
        $this->hooks->emit('lifecycle.failed', $operation, $key, $reason);
        $this->hooks->emit('kernel.trace', '装卸操作失败', ['operation' => $operation, 'unit' => $key, 'reason' => $reason]);
    }

    /** @param array<string, mixed> $context */
    public function package(string $target, string $hook, array $context): void
    {
        $file = $target . '/lifecycle.php';
        if (!is_file($file)) return;
        $callbacks = include $file;
        if (is_array($callbacks) && isset($callbacks[$hook]) && is_callable($callbacks[$hook])) {
            ($callbacks[$hook])($context);
        }
    }
}
