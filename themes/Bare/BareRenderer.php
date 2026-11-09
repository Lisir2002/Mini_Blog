<?php
declare(strict_types=1);
namespace Wx\Themes\Bare;

use Wx\Contracts\RendererInterface;
use Wx\Contracts\SecurityGuardInterface;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;

/**
 * 多布局渲染器：只用 PHP 自身的 include + 原生模板，无模板引擎。
 * 模板与资源的定位委托给 LayoutResolver；本类只把数据交给模板并转义输出。
 * 主题未提供该布局时放弃呈现权，由核心退化为纯数据，绝不兜底输出标签。
 */
final class BareRenderer implements RendererInterface
{
    public function __construct(private string $dir) {}

    public function render(Response $response, Kernel $kernel): Response
    {
        $resolved = (new LayoutResolver($this->dir))->resolve($response->layout());
        if ($resolved === null) return $response;

        /** @var SecurityGuardInterface $guard */
        $guard   = $kernel->container()->resolve(SecurityGuardInterface::class);
        $payload = (array) $kernel->hooks()->apply('theme.context', (array) $response->payload(), $response, $kernel);

        $css       = $resolved['css'];
        $js        = $resolved['js'];
        $title     = (string) ($payload['title'] ?? '未命名站点');
        $items     = (array)  ($payload['items'] ?? []);
        $blocks    = (array)  ($payload['blocks'] ?? []);
        $state     = is_array($payload['state'] ?? null) ? $payload['state'] : null;
        $post      = is_array($payload['post'] ?? null) ? $payload['post'] : null;
        $comments  = is_array($payload['comments'] ?? null)
            ? $payload['comments'] : ['total' => 0, 'items' => [], 'tree' => []];
        $csrf      = (string) ($payload['csrf'] ?? '');
        $apiPrefix = (string) ($payload['api_prefix'] ?? '');
        $esc       = static fn(string $value): string => $guard->escape($value);

        ob_start();
        include $this->dir . '/templates/' . $resolved['file'];
        $html = (string) ob_get_clean();

        return $response
            ->setHeader('Content-Type', 'text/html; charset=utf-8')
            ->setBody($html);
    }
}
