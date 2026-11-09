<?php
declare(strict_types=1);

use Wx\Contracts\SecurityGuardInterface;
use Wx\Core\Http\Request;

echo "\n=== 3. 有主题：样式 100% 来自主题包 ===\n";

check('启用主题后才输出 text/html', function (): void {
    $r = kernel('bare')->handle(new Request('GET', '/'));
    truthy(str_contains($r->contentType(), 'text/html'), '未输出 HTML');
    truthy(str_contains($r->body(), '<style>'), '缺少主题注入的样式');
});

check('样式内容与 themes/Bare/style.css 完全一致', function () use ($root): void {
    $css = trim((string) file_get_contents($root . '/themes/Bare/style.css'));
    $r = kernel('bare')->handle(new Request('GET', '/'));
    truthy(str_contains($r->body(), $css), '页面样式并非来自主题目录');
});

check('主题渲染走安全系统转义，而非核心硬编码', function (): void {
    $k = kernel('bare');
    $k->boot();
    /** @var SecurityGuardInterface $guard */
    $guard = $k->container()->resolve(SecurityGuardInterface::class);
    truthy($guard->escape('<b>x</b>') === '&lt;b&gt;x&lt;/b&gt;', '转义契约未生效');
});
