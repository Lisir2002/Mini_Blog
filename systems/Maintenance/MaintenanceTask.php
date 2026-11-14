<?php
declare(strict_types=1);
namespace Wx\Systems\Maintenance;

use Closure;

/**
 * 一条周期维护任务 —— 只有声明，没有调度知识。
 *
 * 归属系统自己通过 maintenance.tasks 过滤器把任务交上来，因此本层不需要认识
 * 任何业务表、任何清理 SQL。handler 只回答「清了多少、发生了什么」，抛异常由
 * Registry 隔离并记录，绝不影响同批次其它任务。
 *
 * light=true 表示足够轻、可以在请求末尾搭车执行；重任务（扫表、删行）只能 CLI 跑。
 */
final class MaintenanceTask
{
    public function __construct(
        private string $id,
        private int $interval,
        private Closure $handler,
        private string $description = '',
        private bool $light = false
    ) {}

    public function id(): string { return $this->id; }

    /** 最短 60 秒：搭车模式下不允许每个请求都触发一次磁盘扫描。 */
    public function interval(): int { return max(60, $this->interval); }

    public function description(): string { return $this->description; }

    public function light(): bool { return $this->light; }

    /** @return array{removed: int, note: string} */
    public function run(): array
    {
        $result = ($this->handler)();
        return [
            'removed' => (int) ($result['removed'] ?? 0),
            'note'    => (string) ($result['note'] ?? ''),
        ];
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        return [
            'id'          => $this->id,
            'interval'    => $this->interval(),
            'description' => $this->description,
            'light'       => $this->light,
        ];
    }
}
