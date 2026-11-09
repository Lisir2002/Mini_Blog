<?php
declare(strict_types=1);
namespace Wx\Systems\Log;

use Wx\Contracts\LoggerInterface;

/** 默认文件日志实现。站点可通过重新绑定 LoggerInterface 换成 DB / Syslog / 远程日志。 */
final class FileLogger implements LoggerInterface
{
    public function __construct(private string $dir) {}

    public function log(string $level, string $message, array $context = []): void
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
        $extra = $context === [] ? '' : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $line = sprintf("[%s] %-7s %s %s\n", date('Y-m-d H:i:s'), strtoupper($level), $message, $extra);
        @file_put_contents($this->dir . '/' . date('Y-m-d') . '.log', $line, FILE_APPEND);
    }
}
