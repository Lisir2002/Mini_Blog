<?php
declare(strict_types=1);
namespace Wx\Themes\Bare;

use Wx\Contracts\PackageInterface;
use Wx\Contracts\RendererInterface;
use Wx\Core\Kernel;

/**
 * 示例主题包 —— 全站唯一有权写入 HTML/CSS/JS 的地方，后台也不例外。
 * 通过 theme.layouts 钩子自报可用布局槽位；未提供的槽位由主题放弃呈现权。
 */
final class BareTheme implements PackageInterface
{
    private const LAYOUTS = ['site', 'admin', 'tool', 'install', 'deny'];

    public function slug(): string { return 'bare'; }

    public function register(Kernel $kernel): void
    {
        $kernel->container()->singleton(
            RendererInterface::class,
            static fn(): BareRenderer => new BareRenderer(__DIR__),
            'theme:bare',
            20
        );
        $kernel->hooks()->filter('theme.context', static fn(array $ctx): array => $ctx, 10);
        $kernel->hooks()->filter(
            'theme.layouts',
            static fn(array $layouts): array => array_values(array_unique(array_merge($layouts, self::LAYOUTS))),
            10
        );
    }
}
