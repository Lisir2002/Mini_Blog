<?php
declare(strict_types=1);
namespace Wx\Systems\Lifecycle;

use Throwable;
use Wx\Core\HookBus;

/**
 * 文件事务协作者 —— 暂存区、备份、原子替换、失败回滚。
 * 编排者只说「装 / 换 / 还原」，不自己碰一个字节的文件系统操作。
 */
final class FileTransaction
{
    private string $stageDir;
    private string $backupDir;

    public function __construct(string $base, private HookBus $hooks)
    {
        $this->stageDir  = $base . '/staging';
        $this->backupDir = $base . '/backup';
        foreach ([$base, $this->stageDir, $this->backupDir] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new LifecycleException('无法创建装卸工作目录：' . $dir, 50001);
            }
        }
    }

    public function role(): string { return 'file_transaction'; }

    public function exists(string $dir): bool { return is_dir($dir); }

    public function backupPath(string $key, string $version): string
    {
        return $this->backupDir . '/' . str_replace(':', '-', $key) . '-' . $version . '-' . date('YmdHis');
    }

    private function stagePath(string $key): string
    {
        return $this->stageDir . '/' . str_replace(':', '-', $key) . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
    }

    /** 首次安装：源 → 暂存 → 目标；任一步失败，目标与暂存一并清理。 */
    public function install(string $src, string $target, string $key, callable $afterCopy): void
    {
        $this->hooks->emit('lifecycle.collab', $this->role(), 'install');
        $stage = $this->stagePath($key);
        Fs::copyDir($src, $stage);
        try {
            Fs::copyDir($stage, $target);
            $afterCopy();
        } catch (Throwable $e) {
            Fs::removeDir($target);
            Fs::removeDir($stage);
            throw $e;
        }
        Fs::removeDir($stage);
    }

    /** 升级：先留备份再替换；失败自动从备份回滚，升级链路不会留下半截状态。 */
    public function replace(string $src, string $target, string $backup, string $key, callable $afterCopy): void
    {
        $this->hooks->emit('lifecycle.collab', $this->role(), 'replace');
        Fs::copyDir($target, $backup);
        try {
            Fs::removeDir($target);
            $stage = $this->stagePath($key);
            Fs::copyDir($src, $stage);
            Fs::copyDir($stage, $target);
            Fs::removeDir($stage);
            $afterCopy();
        } catch (Throwable $e) {
            Fs::removeDir($target);
            Fs::copyDir($backup, $target);
            throw $e;
        }
    }

    public function restore(string $backup, string $target, string $key): void
    {
        $this->hooks->emit('lifecycle.collab', $this->role(), 'restore');
        $trash = $this->stagePath($key);
        Fs::copyDir($target, $trash);
        Fs::removeDir($target);
        try {
            Fs::copyDir($backup, $target);
        } catch (Throwable $e) {
            Fs::removeDir($target);
            Fs::copyDir($trash, $target);
            throw $e;
        }
        Fs::removeDir($trash);
    }

    public function remove(string $dir): void
    {
        $this->hooks->emit('lifecycle.collab', $this->role(), 'remove');
        Fs::removeDir($dir);
    }
}
