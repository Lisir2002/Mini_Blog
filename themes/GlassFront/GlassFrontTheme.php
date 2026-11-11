<?php
declare(strict_types=1);
namespace Wx\Themes\GlassFront;

use Wx\Contracts\PackageInterface;
use Wx\Contracts\RendererInterface;
use Wx\Core\Kernel;

/**
 * GlassFront 主题包 —— 出厂元主题（初始化主题）之「前端面」，第一款前端主题。
 * 与 Glass 后端元主题同属 family=glass 磨砂玻璃材质，glass.css 逐字同源。
 *
 * 只做三件事：绑定渲染器、自报可用布局槽位、经 theme.context 声明主题身份。
 * 门户文章流与概览一律来自既有公开接口，主题不复制、不内置业务数据。
 */
final class GlassFrontTheme implements PackageInterface
{
    private const LAYOUTS = ['site', 'post', 'archive', 'admin', 'tool', 'install', 'deny'];

    public function slug(): string { return 'glass-front'; }

    public function register(Kernel $kernel): void
    {
        $kernel->container()->singleton(
            RendererInterface::class,
            static fn(): GlassFrontRenderer => new GlassFrontRenderer(__DIR__),
            'theme:glass-front',
            20
        );
        $kernel->hooks()->filter(
            'theme.context',
            static fn(array $ctx): array => array_merge($ctx, [
                'theme_name' => 'GlassFront',
                'theme_surface' => 'front',
                'theme_family' => 'glass',
                'theme_meta' => true,
            ]),
            10
        );
        $kernel->hooks()->filter(
            'theme.layouts',
            static fn(array $layouts): array => array_values(array_unique(array_merge($layouts, self::LAYOUTS))),
            10
        );
    }
}
