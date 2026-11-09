<?php
declare(strict_types=1);
namespace Wx\Contracts;

use Wx\Core\Kernel;

/**
 * 承载包契约 —— 主题 / 插件 / 独立页三者共用同一身份。
 * 「一切都用主题系统、插件系统、独立页系统承载」即由此统一。
 */
interface PackageInterface
{
    public function slug(): string;

    /** 包自己决定：绑定什么契约、订阅什么钩子、注册什么路由。核心不干预。 */
    public function register(Kernel $kernel): void;
}
