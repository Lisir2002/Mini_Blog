<?php
declare(strict_types=1);
namespace Wx\Themes\Glass;

use Wx\Contracts\RendererInterface;
use Wx\Contracts\SecurityGuardInterface;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;

/**
 * 原生 PHP 模板渲染器：只把结构化数据交给模板，不拼接任何标签字符串。
 * 布局未提供时放弃呈现权，由核心退化为纯数据。
 */
final class GlassRenderer implements RendererInterface
{
    public function __construct(private string $dir) {}

    public function render(Response $response, Kernel $kernel): Response
    {
        $resolved = (new LayoutResolver($this->dir))->resolve($response->layout());
        if ($resolved === null) return $response;

        /** @var SecurityGuardInterface $guard */
        $guard     = $kernel->container()->resolve(SecurityGuardInterface::class);
        $payload   = (array) $kernel->hooks()->apply('theme.context', (array) $response->payload(), $response, $kernel);
        $esc       = static fn(string $value): string => $guard->escape($value);
        $css       = $resolved['css'];
        $js        = $resolved['js'];
        $layout    = $response->layout();
        $title     = (string) ($payload['title'] ?? '未命名站点');
        $themeName = (string) ($payload['theme_name'] ?? 'Glass');
        $items     = (array)  ($payload['items'] ?? []);
        $blocks    = (array)  ($payload['blocks'] ?? []);
        $state     = is_array($payload['state'] ?? null) ? $payload['state'] : null;
        $post      = is_array($payload['post'] ?? null) ? $payload['post'] : null;
        $comments  = is_array($payload['comments'] ?? null)
            ? $payload['comments'] : ['total' => 0, 'items' => [], 'tree' => []];
        $csrf      = (string) ($payload['csrf'] ?? '');
        $apiPrefix = (string) ($payload['api_prefix'] ?? '/api/v1');
        // archive 布局的数据契约：与 GlassFront 逐字同源，两款元主题口径一致。
        $view       = (string) ($payload['view'] ?? 'archive');
        $sections   = (array)  ($payload['sections'] ?? []);
        $terms      = (array)  ($payload['terms'] ?? []);
        $term       = is_array($payload['term'] ?? null) ? $payload['term'] : null;
        $total      = (int)    ($payload['total'] ?? 0);
        $shown      = (int)    ($payload['shown'] ?? 0);
        $truncated  = !empty($payload['truncated']);
        $empty      = !empty($payload['empty']);
        $termsState = is_array($payload['terms_state'] ?? null) ? $payload['terms_state'] : null;
        $links      = (array)  ($payload['links'] ?? []);

        ob_start();
        include $this->dir . '/templates/' . $resolved['file'];
        $html = (string) ob_get_clean();

        return $response
            ->setHeader('Content-Type', 'text/html; charset=utf-8')
            ->setBody($html);
    }
}
