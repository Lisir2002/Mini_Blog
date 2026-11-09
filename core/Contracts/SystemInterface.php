<?php
declare(strict_types=1);
namespace Wx\Contracts;

use Wx\Core\Kernel;

/**
 * 系统层契约 —— 所有系统（含未来新增的系统）唯一的共同身份。
 * 核心只认识这个接口，不认识任何具体系统类。
 */
interface SystemInterface
{
    /** 系统层标识，需与 system.json 的 name 一致 */
    public function name(): string;

    public function version(): string;

    /** 阶段一：绑定契约、订阅钩子。此阶段不得解析其他系统的契约实现。 */
    public function register(Kernel $kernel): void;

    /** 阶段二：所有契约已就绪，可安全解析依赖、装载包、建立连接。 */
    public function boot(Kernel $kernel): void;
}
