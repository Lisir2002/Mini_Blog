<?php
declare(strict_types=1);
namespace Wx\Themes\GlassFront;

use Wx\Contracts\RendererInterface;
use Wx\Contracts\SecurityGuardInterface;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;

/**
 * 原生 PHP 模板渲染器：只把结构化数据交给模板，不拼接任何标签字符串。
 * 布局未提供时放弃呈现权，由核心退化为纯数据（规矩 J）。
 */
final class GlassFrontRenderer implements RendererInterface
{
    public function __construct(private string $dir) {}

    public function render(Response $response, Kernel $kernel): Response
    {
        $resolved = (new LayoutResolver($this->dir))->resolve($response->layout());
        if ($resolved === null) return $response;

        /** @var SecurityGuardInterface $guard */
        $guard = $kernel->container()->resolve(SecurityGuardInterface::class);
        $payload = (array) $kernel->hooks()->apply('theme.context', (array) $response->payload(), $response, $kernel);
        $esc = static fn(string $value): string => $guard->escape($value);
        $css = $resolved['css'];
        $js = $resolved['js'];
        $layout = $response->layout();
        $title = (string) ($payload['title'] ?? '未命名站点');
        $themeName = (string) ($payload['theme_name'] ?? 'GlassFront');
        $surface = (string) ($payload['theme_surface'] ?? 'front');
        $family = (string) ($payload['theme_family'] ?? 'glass');
        $meta = !empty($payload['theme_meta']);
        $items = (array) ($payload['items'] ?? []);
        $blocks = (array) ($payload['blocks'] ?? []);
        $state = is_array($payload['state'] ?? null) ? $payload['state'] : null;
        $post = is_array($payload['post'] ?? null) ? $payload['post'] : null;
        $comments = is_array($payload['comments'] ?? null)
            ? $payload['comments'] : ['total' => 0, 'items' => [], 'tree' => []];
        $csrf = (string) ($payload['csrf'] ?? '');
        $apiPrefix = (string) ($payload['api_prefix'] ?? '/api/v1');

        ob_start();
        include $this->dir . '/templates/' . $resolved['file'];
        $html = (string) ob_get_clean();

        return $response
            ->setHeader('Content-Type', 'text/html; charset=utf-8')
            ->setBody($html);
    }
}
