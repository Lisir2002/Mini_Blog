<?php
declare(strict_types=1);

use Wx\Contracts\RendererInterface;
use Wx\Core\Http\Request;

echo "\n=== 1. 无主题：零样式 / 零 HTML（运行时） ===\n";

check('未启用主题时不绑定 RendererInterface', function (): void {
    $k = kernel(null);
    $k->boot();
    truthy(!$k->container()->has(RendererInterface::class), '核心不应绑定任何渲染器');
});

check('响应 Content-Type 不是 text/html', function (): void {
    $r = kernel(null)->handle(new Request('GET', '/'));
    truthy(!str_contains(strtolower($r->contentType()), 'html'), 'Content-Type 意外为 ' . $r->contentType());
});

check('响应体中不存在任何 HTML 标签 / 样式 / 脚本', function (): void {
    $r = kernel(null)->handle(new Request('GET', '/'));
    $body = strtolower($r->body());
    foreach (['<html', '<head', '<body', '<style', '<script', '<link', '<div', '<!doctype', 'stylesheet', 'style='] as $needle) {
        truthy(!str_contains($body, $needle), "响应体出现了表现层内容：{$needle}");
    }
});

check('无主题时退化为纯数据服务（合法 JSON，且插件数据仍可达）', function (): void {
    $r = kernel(null)->handle(new Request('GET', '/'));
    truthy(str_contains($r->contentType(), 'json'), '应为 JSON 数据');
    $data = json_decode($r->body(), true);
    truthy(is_array($data), '响应体不是合法 JSON');
    truthy(in_array('这一行由插件系统通过钩子注入', $data['items'] ?? [], true), '插件钩子链路未生效');
});
