<?php
declare(strict_types=1);
namespace Wx\Systems\Maintenance;

use Throwable;

/**
 * 周期维护的调度器 —— 只负责四件事，别的一概不插手：
 *   1. 收任务（id 唯一）；
 *   2. 记台账（上次跑 / 成败 / 耗时 / 累计清理量）；
 *   3. 按间隔挑到期任务，请求搭车模式只挑 light；
 *   4. 逐任务失败隔离 + 文件锁，一个任务炸了不拖垮整批。
 *
 * 台账是 storage/maintenance/state.json，不用数据库：调度器本身不该要求「先有库才能清理」。
 * 锁用 flock(LOCK_EX|LOCK_NB)：拿不到锁直接跳过本轮，绝不排队阻塞请求。
 *
 * 刻意不做的事：不自动执行（默认 piggyback=false）、不建表、不认识任何业务表、
 * 不替各系统写清理 SQL。清理逻辑由归属层自报，删掉本层业务语义完全不变。
 */
final class MaintenanceRegistry
{
    private string $dir;
    private string $ledgerFile;
    private string $lockFile;
    /** @var array<string, MaintenanceTask> */
    private array $tasks = [];
    /** @var array<string, mixed> */
    private array $ledger;

    /** @param array<string, mixed> $options */
    public function __construct(private string $root, private array $options = [])
    {
        $this->dir        = $root . '/storage/maintenance';
        $this->ledgerFile = $this->dir . '/state.json';
        $this->lockFile   = $this->dir . '/run.lock';
        $this->ledger     = $this->load();
    }

    public function register(MaintenanceTask $task): void
    {
        if ($task->id() === '' || str_contains($task->id(), '..')) {
            throw new MaintenanceException('任务 id 非法：' . $task->id(), 42201);
        }
        if (isset($this->tasks[$task->id()])) {
            throw new MaintenanceException('周期维护任务重名：' . $task->id(), 40901);
        }
        $this->tasks[$task->id()] = $task;
    }

    /** @return list<MaintenanceTask> */
    public function tasks(): array { return array_values($this->tasks); }

    /** @return array<string, mixed> */
    public function ledger(): array { return $this->ledger; }

    /**
     * 挑出本轮该跑的任务。force 忽略间隔；piggyback 只放行 light 任务。
     * @return list<MaintenanceTask>
     */
    public function due(int $now, string $mode, bool $force, ?string $only = null): array
    {
        $out = [];
        foreach ($this->tasks as $id => $task) {
            if ($only !== null && $id !== $only) continue;
            if ($mode === 'piggyback' && !$task->light()) continue;
            if (!$force) {
                $last = (int) ($this->ledger['tasks'][$id]['last_run'] ?? 0);
                if ($last > 0 && $now - $last < $task->interval()) continue;
            }
            $out[] = $task;
        }
        return $out;
    }

    /**
     * 执行一批任务。返回逐任务结果，调用方（CLI / 守卫）自己决定怎么呈现。
     * @return array{mode: string, ran: int, skipped: string, results: list<array<string, mixed>>}
     */
    public function run(string $mode, ?string $only = null, bool $force = false, ?int $now = null): array
    {
        $now ??= time();
        $todo = $this->due($now, $mode, $force, $only);
        if ($todo === []) return ['mode' => $mode, 'ran' => 0, 'skipped' => 'none_due', 'results' => []];

        return $this->withLock($mode, function () use ($todo, $mode, $now): array {
            $results = [];
            foreach ($todo as $task) {
                $id      = $task->id();
                $started = microtime(true);
                try {
                    $out  = $task->run();
                    $ms   = round((microtime(true) - $started) * 1000, 3);
                    $row  = [
                        'last_run' => $now, 'last_ok' => true, 'last_error' => '',
                        'last_ms' => $ms, 'removed'  => $out['removed'], 'note' => $out['note'],
                        'runs'    => (int) ($this->ledger['tasks'][$id]['runs'] ?? 0) + 1,
                        'total_removed' => (int) ($this->ledger['tasks'][$id]['total_removed'] ?? 0) + $out['removed'],
                    ];
                    $results[] = ['id' => $id, 'ok' => true, 'removed' => $out['removed'],
                                  'note' => $out['note'], 'ms' => $ms];
                } catch (Throwable $e) {
                    // 失败隔离：记下现场，继续跑下一个，不让一个坏任务吞掉整批。
                    $row = [
                        'last_run' => $now, 'last_ok' => false,
                        'last_error' => $e::class . ': ' . $e->getMessage(),
                        'last_ms' => round((microtime(true) - $started) * 1000, 3),
                        'removed' => 0, 'note' => '',
                        'runs' => (int) ($this->ledger['tasks'][$id]['runs'] ?? 0) + 1,
                        'total_removed' => (int) ($this->ledger['tasks'][$id]['total_removed'] ?? 0),
                    ];
                    $results[] = ['id' => $id, 'ok' => false, 'removed' => 0,
                                  'note' => $row['last_error'], 'ms' => $row['last_ms']];
                }
                $this->ledger['tasks'][$id] = $row;
            }
            $this->ledger['updated_at'] = $now;
            $this->save();
            return ['mode' => $mode, 'ran' => count($results), 'skipped' => '', 'results' => $results];
        });
    }

    /** 只读自检：任务声明合法、台账目录可写、锁可获取。 */
    public function verify(): array
    {
        $issues = [];
        $ids    = [];
        foreach ($this->tasks as $task) {
            if (in_array($task->id(), $ids, true)) $issues[] = '重复任务 id：' . $task->id();
            $ids[] = $task->id();
            if ($task->interval() < 60) $issues[] = '间隔过短：' . $task->id();
        }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            $issues[] = '台账目录不可创建：' . $this->dir;
        }
        if (!is_writable($this->dir)) $issues[] = '台账目录不可写：' . $this->dir;
        return ['ok' => $issues === [], 'tasks' => count($this->tasks), 'issues' => $issues];
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        $loaded = is_file($this->ledgerFile)
            ? json_decode((string) file_get_contents($this->ledgerFile), true) : [];
        if (!is_array($loaded)) $loaded = [];
        $loaded += ['tasks' => [], 'updated_at' => 0];
        return $loaded;
    }

    private function save(): void
    {
        if (!is_dir($this->dir)) @mkdir($this->dir, 0775, true);
        $json = json_encode($this->ledger, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($this->ledgerFile, $json) === false) {
            throw new MaintenanceException('周期维护台账写入失败：' . $this->ledgerFile, 50001);
        }
    }

    /** @template T @param callable(): T $fn @return T|array<string, mixed> */
    private function withLock(string $mode, callable $fn): mixed
    {
        if (!is_dir($this->dir)) @mkdir($this->dir, 0775, true);
        $handle = @fopen($this->lockFile, 'c');
        if ($handle === false) {
            return ['mode' => $mode, 'ran' => 0, 'skipped' => 'lock_unavailable', 'results' => []];
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return ['mode' => $mode, 'ran' => 0, 'skipped' => 'locked', 'results' => []];
        }
        try {
            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
