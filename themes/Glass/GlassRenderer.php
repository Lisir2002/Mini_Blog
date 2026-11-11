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
        // archive 模板与 GlassFront 逐字同源，需要同名的 family 变量；
        // 缺了它会在模板里变成「未定义变量 → 传给 string 形参」的 500。
        $family    = (string) ($payload['theme_family'] ?? 'glass');
        $items     = (array)  ($payload['items'] ?? []);
        $blocks    = (array)  ($payload['blocks'] ?? []);
        $state     = is_array($payload['state'] ?? null) ? $payload['state'] : null;
        // 拒绝页 / 404 页共用 deny 槽位：状态码决定标题，不能把 404 说成 403。
        $status    = (int) ($payload['status'] ?? 403);
        $post      = is_array($payload['post'] ?? null) ? $payload['post'] : null;
        $attachments = is_array($payload['attachments'] ?? null)
            ? $payload['attachments'] : ['items' => [], 'total' => 0, 'state' => null, 'message' => ''];
        $comments  = is_array($payload['comments'] ?? null)
            ? $payload['comments'] : ['total' => 0, 'items' => [], 'tree' => []];
        $csrf      = (string) ($payload['csrf'] ?? '');
        $visitorId = (string) ($payload['visitor_id'] ?? '');
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
        // 筛选与分页：site_total 与 total 是两套口径，模板不能混用。
        $siteTotal  = (int)    ($payload['site_total'] ?? 0);
        $filter     = (array)  ($payload['filter'] ?? []);
        $filterAction = (string) ($payload['filter_action'] ?? '/archive');
        $filterIncomplete = !empty($payload['filter_incomplete']);
        $pageNo     = (int)    ($payload['page_no'] ?? 1);
        $pages      = (int)    ($payload['pages'] ?? 1);
        $perPage    = (int)    ($payload['per_page'] ?? 20);
        $hasPrev    = !empty($payload['has_prev']);
        $hasNext    = !empty($payload['has_next']);
        $beyond     = !empty($payload['beyond']);
        $prevHref   = (string) ($payload['prev_href'] ?? '');
        $nextHref   = (string) ($payload['next_href'] ?? '');

        ob_start();
        include $this->dir . '/templates/' . $resolved['file'];
        $html = (string) ob_get_clean();

        return $response
            ->setHeader('Content-Type', 'text/html; charset=utf-8')
            ->setBody($html);
    }
}
