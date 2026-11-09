<?php
declare(strict_types=1);
namespace Wx\Core;

use Wx\Contracts\SystemInterface;

/** 系统层基类：把样板代码压到最少，让每个系统层只写自己真正关心的逻辑。 */
abstract class AbstractSystem implements SystemInterface
{
    public function version(): string { return '0.1.0'; }

    /** 默认不绑定契约；纯装载型系统层（主题 / 插件 / 独立页）可只覆写 boot()。 */
    public function register(Kernel $kernel): void {}

    /** 默认无启动逻辑。需要装载包 / 建立连接的系统层覆写此方法。 */
    public function boot(Kernel $kernel): void {}
}
