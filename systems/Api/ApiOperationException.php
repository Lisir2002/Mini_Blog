<?php
declare(strict_types=1);
namespace Wx\Systems\Api;

/** 接口层异常：携带规范化错误码，交由分发器翻译成统一信封。 */
final class ApiOperationException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $apiCode = 50101)
    {
        parent::__construct($message);
    }
}
