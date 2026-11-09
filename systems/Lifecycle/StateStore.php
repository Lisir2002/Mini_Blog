<?php
declare(strict_types=1);
namespace Wx\Systems\Lifecycle;

/** 装卸状态持久化：单一 JSON 文件，含单元状态与完整操作历史（可审计、可回溯）。 */
final class StateStore
{
    private string $file;
    private array $state;

    public function __construct(private string $dir, private ?\Wx\Core\HookBus $hooks = null)
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new LifecycleException('无法创建状态目录：' . $dir, 50001);
        }
        $this->file = $dir . '/state.json';
        $loaded = is_file($this->file) ? json_decode((string) file_get_contents($this->file), true) : [];
        $this->state = is_array($loaded) ? $loaded : [];
        $this->state += ['units' => [], 'history' => []];
    }

    public function all(): array { return $this->state; }

    /** @return array<string, mixed> */
    public function unit(string $key): array
    {
        return $this->state['units'][$key] ?? ['state' => 'absent'];
    }

    public function has(string $key): bool { return isset($this->state['units'][$key]); }

    public function set(string $key, array $record): void
    {
        $this->hooks?->emit('lifecycle.collab', 'state_store', 'set');
        $this->state['units'][$key] = $record;
        $this->save();
    }

    public function remove(string $key): void
    {
        unset($this->state['units'][$key]);
        $this->save();
    }

    /** @param array<string, mixed> $extra */
    public function history(string $key, string $operation, string $from, string $to, array $extra = []): void
    {
        $this->state['history'][] = [
            'at' => date(DATE_ATOM), 'unit' => $key, 'operation' => $operation,
            'from' => $from, 'to' => $to,
        ] + $extra;
        $this->save();
    }

    private function save(): void
    {
        $json = json_encode($this->state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($this->file, $json) === false) {
            throw new LifecycleException('装卸状态写入失败：' . $this->file, 50001);
        }
    }
}
