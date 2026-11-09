<?php
declare(strict_types=1);
namespace Wx\Themes\BareHtml;

use Wx\Contracts\RendererInterface;
use Wx\Contracts\SecurityGuardInterface;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;

/**
 * 原生 PHP 模板渲染器（无模板引擎、无样式文件）。
 * 只认 site 槽位；其余布局本主题未提供 → 放弃呈现权，交回核心退化为纯数据。
 */
final class BareHtmlRenderer implements RendererInterface
{
    public function __construct(private string $dir) {}

    public function render(Response $response, Kernel $kernel): Response
    {
        if ($response->layout() !== 'site') return $response;

        /** @var SecurityGuardInterface $guard */
        $guard   = $kernel->container()->resolve(SecurityGuardInterface::class);
        $payload = (array) $kernel->hooks()->apply('theme.context', (array) $response->payload(), $response, $kernel);
        $title   = (string) ($payload['title'] ?? '未命名站点');
        $items   = (array)  ($payload['items'] ?? []);
        $esc     = static fn(string $value): string => $guard->escape($value);

        ob_start();
        include $this->dir . '/templates/page.php';
        $html = (string) ob_get_clean();

        return $response
            ->setHeader('Content-Type', 'text/html; charset=utf-8')
            ->setBody($html);
    }
}
