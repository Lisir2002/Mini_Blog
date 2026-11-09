<?php
declare(strict_types=1);
namespace Wx\Themes\Glass;

use Wx\Contracts\PackageInterface;
use Wx\Contracts\RendererInterface;
use Wx\Core\Kernel;

/**
 * Glass 主题包 —— 半透明磨砂玻璃质感，后台仪表盘为首个重点布局。
 *
 * 只做三件事：绑定渲染器、自报可用布局槽位、经 theme.context 声明主题身份。
 * 系统层清单与业务数据一律来自既有公开接口，主题不复制、不内置。
 */
final class GlassTheme implements PackageInterface
{
    private const LAYOUTS = ['site', 'post', 'admin', 'tool', 'install', 'deny'];

    public function slug(): string { return 'glass'; }

    public function register(Kernel $kernel): void
    {
        $kernel->container()->singleton(
            RendererInterface::class,
            static fn(): GlassRenderer => new GlassRenderer(__DIR__),
            'theme:glass',
            20
        );
        $kernel->hooks()->filter(
            'theme.context',
            static fn(array $ctx): array => array_merge($ctx, ['theme_name' => 'Glass']),
            10
        );
        $kernel->hooks()->filter(
            'theme.layouts',
            static fn(array $layouts): array => array_values(array_unique(array_merge($layouts, self::LAYOUTS))),
            10
        );
    }
}
