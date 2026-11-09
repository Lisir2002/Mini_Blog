<?php
declare(strict_types=1);
namespace Wx\Systems\Log;

use Wx\Contracts\LoggerInterface;
use Wx\Core\AbstractSystem;
use Wx\Core\Kernel;

/** 日志系统：绑定 LoggerInterface，并订阅内核的可观测性出口。 */
final class LogSystem extends AbstractSystem
{
    public function name(): string { return 'log'; }

    public function register(Kernel $kernel): void
    {
        $kernel->container()->singleton(
            LoggerInterface::class,
            static fn(): FileLogger => new FileLogger($kernel->root() . '/storage/logs'),
            'system:log',
            10
        );

        $kernel->hooks()->on('kernel.trace', static function (string $message, array $context) use ($kernel): void {
            $kernel->container()->resolve(LoggerInterface::class)->log('info', $message, $context);
        }, 10);
    }
}
