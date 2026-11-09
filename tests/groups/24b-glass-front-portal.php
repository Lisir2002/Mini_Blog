<?php
declare(strict_types=1);

use Wx\Contracts\RendererInterface;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;

echo "\n=== 24b. 第一款前端元主题 GlassFront：门户呈现与边界 ===\n";

$dir = $root . '/themes/GlassFront';
$slots = ['site', 'post', 'admin', 'tool', 'install', 'deny'];

check('清单与实现类一致，装配后自报六布局、绑定渲染器', function () use ($root, $dir, $slots): void {
    $m = json_decode((string) file_get_contents($dir . '/theme.json'), true);
    truthy(($m['slug'] ?? null) === 'glass-front', 'slug 不符');
    truthy(($m['layouts'] ?? []) === $slots, '清单布局与约定不一致');
    truthy(is_subclass_of((string) $m['class'], \Wx\Contracts\PackageInterface::class),
        '主题类未实现 PackageInterface');
    $k = new Kernel($root, ['theme' => ['active' => 'glass-front']]);
    $k->boot();
    truthy($k->container()->has(RendererInterface::class), '未绑定 RendererInterface');
    $declared = (array) $k->hooks()->apply('theme.layouts', []);
    foreach ($slots as $s) {
        truthy(in_array($s, $declared, true), '未自报布局：' . $s);
    }
});

check('六布局都是原生 PHP 模板，渲染器只 include 不拼标签', function () use ($dir, $slots): void {
    foreach ($slots as $s) {
        $file = $dir . '/templates/' . ($s === 'site' ? 'page.php' : $s . '.php');
        truthy(is_file($file), '缺少布局模板：' . $s);
        truthy(str_contains((string) file_get_contents($file), '<?='), $s . ' 未用原生短输出');
    }
    $r = (string) file_get_contents($dir . '/GlassFrontRenderer.php');
    truthy(str_contains($r, 'include $this->dir'), '渲染器未 include 原生模板');
    truthy(!str_contains($r, '<html'), '渲染器在拼接 HTML 字符串');
    truthy(str_contains($r, 'SecurityGuardInterface'), '渲染器未走安全转义契约');
});

check('CSS/JS 逐字来自主题包，无内联兜底；主题自包含不跨包引用', function () use ($dir): void {
    $r = pageKernel('glass-front')->handle(new Request('GET', '/', [], []));
    foreach (['assets/glass.css', 'assets/front.css', 'assets/front.js'] as $rel) {
        $t = trim((string) file_get_contents($dir . '/' . $rel));
        truthy($t !== '' && str_contains($r->body(), $t), '资源未逐字注入：' . $rel);
    }
    $php = '';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') $php .= (string) file_get_contents($f->getPathname());
    }
    foreach (['../Glass/', '/themes/Glass/', 'Glass/templates', 'Glass/assets'] as $needle) {
        truthy(!str_contains($php, $needle), '主题跨包引用了 Glass 后端包：' . $needle);
    }
});

check('首页门户：元主题身份标记 + 导航 + 概览 + 文章流容器', function (): void {
    $r = pageKernel('glass-front')->handle(new Request('GET', '/', [], []));
    truthy(str_contains($r->contentType(), 'text/html'), '首页未输出 HTML');
    foreach (['data-surface="front"', 'data-family="glass"', 'data-meta="1"',
              'data-layout="site"', 'data-api="/api/v1"', 'front-nav', 'front-hero',
              'id="front-posts"', 'id="front-stats"', 'glass-aurora', 'backdrop-filter'] as $n) {
        truthy(str_contains($r->body(), $n), '首页缺少：' . $n);
    }
});

check('门户为渐进增强：非首页复用 site 布局时无文章容器，脚本不发请求', function () use ($dir): void {
    $links = pageKernel('glass-front')->handle(new Request('GET', '/links', [], []));
    truthy(!str_contains($links->body(), 'id="front-posts"'), '友链页误带门户文章容器');
    $js = (string) file_get_contents($dir . '/assets/front.js');
    truthy(str_contains($js, "getElementById('front-posts')"), '脚本缺少容器存在性 gate');
    truthy(str_contains($js, "layout !== 'site'"), '脚本缺少布局 gate');
});

check('门户探针逐条对应已登记的公开接口，不臆造端点', function () use ($root, $dir): void {
    $js = (string) file_get_contents($dir . '/assets/front.js');
    preg_match_all("#path:\s*'([^']+)'#", $js, $m);
    $paths = array_values(array_unique($m[1]));
    truthy(count($paths) >= 4, '探针过少，守卫可能失效');
    $j = json_decode((string) file_get_contents($root . '/api/registry.json'), true);
    $public = [];
    foreach ($j['endpoints'] as $e) {
        if (($e['auth'] ?? '') === 'public' && $e['method'] === 'GET') $public[] = $e['path'];
    }
    foreach ($paths as $p) {
        truthy(in_array('/api/v1' . $p, $public, true), '探针非公开 GET 接口或未登记：' . $p);
    }
});

check('数据来自既有系统：不写 SQL、不硬编码文章内容、不内置业务数据', function () use ($dir): void {
    $php = '';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') $php .= (string) file_get_contents($f->getPathname());
    }
    $upper = strtoupper($php);
    foreach (['SELECT ', 'INSERT INTO', 'CREATE TABLE', 'UPDATE ', 'DELETE FROM'] as $sql) {
        truthy(!str_contains($upper, $sql), '主题出现 SQL：' . $sql);
    }
    truthy(!str_contains($php, '第一篇文章'), '主题硬编码了文章标题');
    truthy(!str_contains($php, '第二篇文章'), '主题硬编码了文章标题');
});

check('输出走 textContent / createElement，令牌无持久化、无注入、无写操作', function () use ($dir): void {
    $js = (string) file_get_contents($dir . '/assets/front.js');
    foreach (['localStorage', 'sessionStorage', 'document.cookie', 'innerHTML', 'eval('] as $n) {
        truthy(!str_contains($js, $n), '出现不安全用法：' . $n);
    }
    truthy(str_contains($js, 'textContent'), '未使用 textContent 输出');
    truthy(str_contains($js, 'createElement'), '未使用 createElement 构建节点');
    truthy(!preg_match("/method:\s*'POST'|method:\s*\"POST\"|fetch\([^)]*POST/", $js), '脚本存在写操作');
});

check('各布局真实渲染且槽位正确；未登录拒绝走 deny；无主题退化为纯数据', function (): void {
    $cases = [
        ['/', [], 'site'],
        ['/links', [], 'site'],
        ['/post', ['slug' => 'first-post'], 'post'],
        ['/toolbox', [], 'tool'],
        ['/install', [], 'install'],
        ['/console', [], 'admin'],
    ];
    foreach ($cases as [$path, $q, $slot]) {
        $r = pageKernel('glass-front')->handle(new Request('GET', $path, $q, adminHeaders()));
        truthy(str_contains($r->contentType(), 'text/html'), $path . ' 未输出 HTML');
        truthy(str_contains($r->body(), 'backdrop-filter'), $path . ' 未带玻璃样式');
        truthy(str_contains($r->body(), 'data-layout="' . $slot . '"'), $path . ' 槽位不符');
    }
    $denied = pageKernel('glass-front')->handle(new Request('GET', '/console', [], []));
    truthy(str_contains($denied->body(), 'data-layout="deny"'), '拒绝页未用 deny 布局');
    truthy(str_contains($denied->body(), '403 · GlassFront'), '缺少拒绝页标识');
    $plain = pageKernel(null)->handle(new Request('GET', '/', [], adminHeaders()));
    truthy(str_contains($plain->contentType(), 'json'), '无主题时未退化为纯数据');
    foreach (['<html', '<style', 'class='] as $n) {
        truthy(!str_contains(strtolower($plain->body()), $n), '无主题时出现呈现痕迹：' . $n);
    }
});

check('主题渲染经安全系统转义，注入标题不会成为标签', function () use ($root): void {
    $k = adminKernel(['theme' => ['active' => 'glass-front']]);
    $k->hooks()->filter('theme.context', static function (array $ctx): array {
        $ctx['title'] = '<script>alert(1)</script>';
        return $ctx;
    }, 99);
    $r = $k->handle(new Request('GET', '/', [], []));
    truthy(!str_contains($r->body(), '<script>alert(1)</script>'), '标题未转义');
    truthy(str_contains($r->body(), '&lt;script&gt;'), '未见转义结果');
});
