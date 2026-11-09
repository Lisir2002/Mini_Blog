<?php
declare(strict_types=1);
namespace Wx\Systems\Theme;

use Wx\Core\AbstractSystem;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;
use Wx\Core\PackageLoader;

/**
 * 主题系统 —— 三大承载系统之一。
 *
 * 它本身不渲染任何东西，只负责「装载当前启用的主题包」。
 * 主题包自己决定绑定哪个 RendererInterface 实现、输出什么 HTML/CSS/JS。
 * 未配置主题 → 不绑定渲染器 → 核心保持零呈现。
 */
final class ThemeSystem extends AbstractSystem
{
    public function name(): string { return 'theme'; }

    public function register(Kernel $kernel): void
    {
        // 呈现权归主题系统：主题未声明的布局槽位，一律不渲染（退化为纯数据）。
        // 这保证后台 / 工具页 / 安装向导都不可能绕开主题擅自产出标签或样式。
        $kernel->hooks()->filter('render.should_render', static function (
            bool $should, Response $response, Request $request, Kernel $k
        ): bool {
            if (!$should) return false;
            $available = (array) $k->hooks()->apply('theme.layouts', []);
            return $available === [] || in_array($response->layout(), $available, true);
        }, 10);
    }

    public function boot(Kernel $kernel): void
    {
        $active = $kernel->config()->get('theme.active');
        if ($active === null || $active === '' || $active === false) {
            $kernel->trace('未启用主题，框架运行于零呈现 / Headless 模式');
            return;
        }

        $loader = new PackageLoader($kernel, $kernel->root() . '/themes', 'theme.json');
        $packages = $loader->load([(string) $active]);
        if ($packages === []) {
            // 主题被装卸系统停用 / 不存在时优雅降级：保持零呈现，而不是抛错或回退到兜底模板
            $kernel->trace('启用的主题不可用，保持零呈现', ['theme' => $active]);
            return;
        }
        foreach ($packages as $slug => $package) {
            $package['instance']->register($kernel);
            $kernel->hooks()->emit('theme.loaded', $slug, $kernel);
        }
    }
}
