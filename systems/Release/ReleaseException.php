<?php
declare(strict_types=1);
namespace Wx\Systems\Release;

/** 版本层异常：把「不可升级 / 迁移冲突 / 不可回退」变成可判定的错误码，而不是一句字符串。 */
final class ReleaseException extends \RuntimeException
{
    public function __construct(string $message, int $code = 40901)
    {
        parent::__construct($message, $code);
    }
}
