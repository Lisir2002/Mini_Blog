<?php
declare(strict_types=1);
namespace Wx\Systems\Page;

use Wx\Core\AbstractSystem;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;
use Wx\Core\PackageLoader;

/**
 * 独立页系统 —— 三大承载系统之一。
 *
 * 独立页包只做两件事：声明路由、产出结构化数据。它不产出任何 HTML/CSS/JS，
 * 也不自己判断「谁可以访问」——访问策略写在 page.json 的 access 段，
 * 由本系统层在 route.access 可编程裁决点统一询问权限契约。
 *
 * 「用哪个布局槽位」写在 page.json 的 layout 字段里，由 render.layout 告知主题系统；
 * 主题包自己决定该布局存不存在、长什么样。拒绝页同样只声明 deny 槽位，
 * 主题没有就退化为纯数据，框架绝不兜底输出标签（规矩 J / L）。
 */
final class PageSystem extends AbstractSystem
{
    public function name(): string { return 'page'; }

    public function boot(Kernel $kernel): void
    {
        $only   = (array) $kernel->config()->get('page.enabled', []);
        $loader = new PackageLoader($kernel, $kernel->root() . '/pages', 'page.json');
        $policy = new PagePolicy($kernel);
        $guard  = new PageAccessGuard($kernel);
        $layoutByPath = [];

        // 身份贯通（读路径）：独立页在 route.resolve 里可以取回「本次请求已裁决的身份」，
        // 不必自己解析令牌。写操作仍只认请求头，守卫对非 GET 不读 Cookie 的边界不变。
        $kernel->container()->bind(
            PageAccessGuard::class, static fn(): PageAccessGuard => $guard, 'system:page', 10, true
        );

        foreach ($loader->load($only === [] ? null : $only) as $slug => $package) {
            $meta = $package['meta'];
            $policy->add((string) $slug, $meta);
            if (isset($meta['layout'])) {
                foreach ((array) ($meta['routes'] ?? []) as $path) {
                    $layoutByPath[(string) $path] = (string) $meta['layout'];
                }
            }
            $package['instance']->register($kernel);
            $kernel->hooks()->emit('page.loaded', $slug, $kernel);
        }

        // 访问裁决：只针对清单声明过的页面路径，统一走「认证器 + 权限决策器」
        $kernel->hooks()->filter('route.access', static function (
            Response $response, Request $request
        ) use ($policy, $guard): Response {
            return $guard->guard($response, $request, $policy);
        }, 10);

        // 身份贯通：页面数据统一带上本次请求的身份，独立页一行不改
        $kernel->hooks()->filter('route.response', static function (
            Response $response, Request $request
        ) use ($guard): Response {
            $data = $response->payload();
            if (is_array($data)) {
                $data['identity'] = $guard->identityFor($request);
                $response->setPayload($data);
            }
            return $response;
        }, 30);

        // 只声明「布局意图」，不裁决渲染与否，更不提供任何样式
        if ($layoutByPath !== []) {
            $kernel->hooks()->filter('render.layout', static function (
                string $layout, Response $response, Request $request
            ) use ($layoutByPath): string {
                // 拒绝响应保留 deny 槽位，不能被页面常规布局覆盖
                if ($response->status() >= 400) return $layout;
                return $layoutByPath[$request->path()] ?? $layout;
            }, 20);
        }

        foreach ($policy->undeclared() as $slug) {
            $kernel->hooks()->emit('page.access.undeclared', $slug, $kernel);
        }
    }
}
