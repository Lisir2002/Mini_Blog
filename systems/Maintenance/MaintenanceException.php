<?php
declare(strict_types=1);
namespace Wx\Systems\Maintenance;

use RuntimeException;

/** 调度器自身的声明错误（任务重名、台账不可写）；任务执行异常一律由 Registry 隔离，不走这里。 */
final class MaintenanceException extends RuntimeException
{
    public function __construct(string $message, private int $codeNum)
    {
        parent::__construct($message);
    }

    public function codeNum(): int { return $this->codeNum; }
}
