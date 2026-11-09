<?php
declare(strict_types=1);
namespace Wx\Themes\BareHtml;

use Wx\Contracts\PackageInterface;
use Wx\Contracts\RendererInterface;
use Wx\Core\Kernel;

/**
 * 裸 HTML 主题包：只提供 site 布局，且零 CSS。
 * 后台等槽位刻意不提供 —— 主题没有的布局，框架绝不兜底，页面退化为纯数据。
 */
final class BareHtmlTheme implements PackageInterface
{
    public function slug(): string { return 'bare-html'; }

    public function register(Kernel $kernel): void
    {
        $kernel->container()->singleton(
            RendererInterface::class,
            static fn(): BareHtmlRenderer => new BareHtmlRenderer(__DIR__),
            'theme:bare-html',
            20
        );
        $kernel->hooks()->filter('theme.context', static fn(array $ctx): array => $ctx, 10);
        $kernel->hooks()->filter(
            'theme.layouts',
            static fn(array $layouts): array => array_values(array_unique(array_merge($layouts, ['site']))),
            10
        );
    }
}
