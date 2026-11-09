<?php
declare(strict_types=1);
namespace Wx\Contracts;

interface SecurityGuardInterface
{
    /** 转义输出。注意：转义发生在主题渲染层，核心不输出 HTML，天然缩小了 XSS 面。 */
    public function escape(string $value): string;

    public function token(): string;

    public function verify(string $token): bool;
}
