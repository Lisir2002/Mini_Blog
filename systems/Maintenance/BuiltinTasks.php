<?php
declare(strict_types=1);
namespace Wx\Systems\Maintenance;

/**
 * 内置任务：只处理「文件系统上的自身残留」，不碰任何业务表。
 *
 * 为什么只有两条：日志与临时文件是核心 / 装卸层自己留下的、没有归属系统能替它
 * 自报的产物。业务侧清理（指标裁剪、状态过期）由归属层经 maintenance.tasks 自报，
 * 本文件不去复制它们的表知识与保留策略。
 */
final class BuiltinTasks
{
    /** @param array<string, mixed> $options @return list<MaintenanceTask> */
    public static function all(string $root, array $options): array
    {
        $logDays = max(1, (int) ($options['log_retention_days'] ?? 30));
        $tmpHrs  = max(1, (int) ($options['tmp_retention_hours'] ?? 24));

        return [
            new MaintenanceTask(
                'logs.rotate', 86400,
                static fn(): array => self::rotateLogs($root . '/storage/logs', $logDays),
                "删除 {$logDays} 天前的按日日志文件", true
            ),
            new MaintenanceTask(
                'storage.part', 3600,
                static fn(): array => self::clearPartial($root . '/storage', $tmpHrs * 3600),
                "清理 {$tmpHrs} 小时前残留的 .part / .tmp 文件", true
            ),
        ];
    }

    /** @return array{removed: int, note: string} */
    private static function rotateLogs(string $dir, int $days): array
    {
        if (!is_dir($dir)) return ['removed' => 0, 'note' => '日志目录不存在'];
        $cutoff  = date('Y-m-d', time() - $days * 86400);
        $removed = 0;
        foreach (glob($dir . '/*.log') ?: [] as $file) {
            // 文件名本身就是日期（FileLogger 按 Y-m-d.log 落盘），不靠 mtime 猜：
            // 归档 / 备份恢复后 mtime 会变，日期名不会。
            $day = basename($file, '.log');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1 || $day >= $cutoff) continue;
            if (@unlink($file)) $removed++;
        }
        return ['removed' => $removed, 'note' => "保留 {$days} 天，截止 {$cutoff}"];
    }

    /** @return array{removed: int, note: string} */
    private static function clearPartial(string $dir, int $maxAge): array
    {
        if (!is_dir($dir)) return ['removed' => 0, 'note' => 'storage 目录不存在'];
        $cutoff  = time() - $maxAge;
        $removed = 0;
        $files   = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if (!$file->isFile()) continue;
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, ['part', 'tmp'], true)) continue;
            if ($file->getMTime() > $cutoff) continue;
            if (@unlink($file->getPathname())) $removed++;
        }
        return ['removed' => $removed, 'note' => "超过 {$maxAge} 秒的半截文件"];
    }
}
