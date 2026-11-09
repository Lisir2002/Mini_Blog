<?php
declare(strict_types=1);
namespace Wx\Systems\Lifecycle;

/** 装卸异常：携带规范化 API 错误码，由接口系统统一翻译成信封。 */
final class LifecycleException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $apiCode = 40901)
    {
        parent::__construct($message);
    }
}
