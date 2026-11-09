<?php
declare(strict_types=1);
namespace Wx\Systems\Lifecycle;

use Wx\Core\HookBus;

/**
 * 版本快照仓库 —— 每次替换前留一份「当时那个版本」的完整目录。
 *
 * 为什么不是单个 backup 字段：单备份只能回退一步，且回退一次就被清空。
 * 站长真正需要的是「回到任意一个装过的版本」，所以按版本归档、保留 N 份。
 *
 * 快照路径：storage/lifecycle/releases/<key>/<version>-<时间戳>/
 * 保留策略只删最旧的快照，绝不删当前版本，也不删最近一份。
 */
final class ReleaseArchive
{
    private string $dir;

    public function __construct(string $base, private HookBus $hooks, private int $keep = 5)
    {
        $this->dir = $base . '/releases';
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new LifecycleException('无法创建版本仓库：' . $this->dir, 50001);
        }
    }

    public function role(): string { return 'release_archive'; }

    /** 把当前目录完整复制成一份带版本号的快照，返回快照绝对路径。 */
    public function snapshot(string $sourceDir, string $key, string $version): string
    {
        $this->hooks->emit('lifecycle.collab', $this->role(), 'snapshot');
        $slot = $this->dir . '/' . str_replace(':', '-', $key);
        if (!is_dir($slot) && !@mkdir($slot, 0775, true) && !is_dir($slot)) {
            throw new LifecycleException('无法创建版本槽位：' . $slot, 50001);
        }
        $safe = preg_replace('/[^0-9A-Za-z._-]/', '_', $version) ?: '0.0.0';
        // 时间戳精确到微秒：同一秒内连做两次升级时，「最近 N 份」仍能确定顺序。
        $path = $slot . '/' . $safe . '-' . self::stamp();
        if (is_dir($path)) {
            $path .= '-' . bin2hex(random_bytes(2));
        }
        Fs::copyDir($sourceDir, $path);
        $this->prune($key);
        return $path;
    }

    /**
     * 快照名的时序戳：14 位秒 + 6 位微秒，定长可直接按字符串比较。
     * 只有名字里带戳，目录 mtime 的秒级精度才不会把同一秒的两次快照排成随机顺序。
     */
    private static function stamp(): string
    {
        [$usec, $sec] = explode(' ', microtime());
        return date('YmdHis', (int) $sec) . sprintf('%06d', (int) ((float) $usec * 1000000));
    }

    /** @return list<array{version: string, path: string, at: string, bytes: int}> 按时间倒序 */
    public function all(string $key): array
    {
        $slot = $this->dir . '/' . str_replace(':', '-', $key);
        $rows = [];
        foreach (glob($slot . '/*', GLOB_ONLYDIR) ?: [] as $path) {
            $name = basename($path);
            $rows[] = [
                'version' => str_replace('_', '.', preg_replace('/-\d{14}(\d{6})?(-[0-9a-f]+)?$/', '', $name) ?? $name),
                'path'    => $path,
                'at'      => date(DATE_ATOM, (int) (@filemtime($path) ?: time())),
                'bytes'   => $this->size($path),
                // 只用于排序：微秒戳缺失的老快照退化为 0，排在同时刻的新快照之后。
                'stamp'   => preg_match('/-(\d{14})(\d{6})?/', $name, $m) === 1 ? $m[1] . ($m[2] ?? '000000') : '',
            ];
        }
        usort($rows, static fn(array $a, array $b): int => [$b['stamp'], $b['path']] <=> [$a['stamp'], $a['path']]);
        return array_map(static function (array $row): array {
            unset($row['stamp']);
            return $row;
        }, $rows);
    }

    public function find(string $key, string $version): ?array
    {
        foreach ($this->all($key) as $entry) {
            if ($entry['version'] === $version) return $entry;
        }
        return null;
    }

    /** 恢复快照到目标目录；恢复前的现场先留一份临时副本，失败自动还原。 */
    public function restore(string $snapshot, string $target, string $key): void
    {
        $this->hooks->emit('lifecycle.collab', $this->role(), 'restore');
        $trash = $this->dir . '/.trash-' . str_replace(':', '-', $key) . '-' . bin2hex(random_bytes(3));
        Fs::copyDir($target, $trash);
        Fs::removeDir($target);
        try {
            Fs::copyDir($snapshot, $target);
        } catch (\Throwable $e) {
            Fs::removeDir($target);
            Fs::copyDir($trash, $target);
            throw $e;
        }
        Fs::removeDir($trash);
    }

    /** 只保留最近 N 份；返回删除数量。 */
    public function prune(string $key): int
    {
        $all = $this->all($key);
        $drop = array_slice($all, max(1, $this->keep));
        foreach ($drop as $entry) Fs::removeDir($entry['path']);
        return count($drop);
    }

    private function size(string $dir): int
    {
        $sum = 0;
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        ) as $file) {
            if ($file->isFile()) $sum += (int) $file->getSize();
        }
        return $sum;
    }
}
