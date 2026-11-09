<?php
declare(strict_types=1);

use Wx\Contracts\RendererInterface;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;

echo "\n=== 23a. Glass 主题：清单 / 布局 / 磨砂玻璃基元 / 零兜底 ===\n";

$dir   = $root . '/themes/Glass';
$slots = ['site', 'post', 'admin', 'tool', 'install', 'deny'];

check('主题清单与实现类一致，装配后自报六个布局槽位', function () use ($root, $dir, $slots): void {
    $meta = json_decode((string) file_get_contents($dir . '/theme.json'), true);
    truthy(($meta['slug'] ?? null) === 'glass', '主题 slug 不是 glass');
    truthy(($meta['layouts'] ?? []) === $slots, '清单声明的布局槽位与约定不一致');
    truthy(is_subclass_of((string) $meta['class'], \Wx\Contracts\PackageInterface::class), '主题类未实现 PackageInterface');

    $k = new Kernel($root, ['theme' => ['active' => 'glass']]);
    $k->boot();
    truthy($k->container()->has(RendererInterface::class), '主题未绑定 RendererInterface');
    $declared = (array) $k->hooks()->apply('theme.layouts', []);
    foreach ($slots as $slot) {
        truthy(in_array($slot, $declared, true), '主题未自报布局槽位：' . $slot);
    }
});

check('六个布局都是原生 PHP 模板，渲染器只 include 不拼标签', function () use ($dir, $slots): void {
    foreach ($slots as $slot) {
        $file = $dir . '/templates/' . ($slot === 'site' ? 'page.php' : $slot . '.php');
        truthy(is_file($file), '缺少布局模板：' . $slot);
        $src = (string) file_get_contents($file);
        truthy(str_contains($src, '<?='), $slot . ' 未使用原生 PHP 短输出');
    }
    $renderer = (string) file_get_contents($dir . '/GlassRenderer.php');
    truthy(str_contains($renderer, 'include $this->dir'), '渲染器未 include 原生模板');
    truthy(!str_contains($renderer, '<html'), '渲染器在拼接 HTML 字符串');
    truthy(str_contains($renderer, 'SecurityGuardInterface'), '渲染器未走安全系统转义契约');
});

check('磨砂玻璃三要素：半透明底 + 背景模糊 + 无支持时降级', function () use ($dir): void {
    $css = (string) file_get_contents($dir . '/assets/glass.css');
    truthy(str_contains($css, 'backdrop-filter: blur'), '缺少背景模糊');
    truthy(str_contains($css, 'rgba(255, 255, 255, .07)'), '缺少半透明玻璃底');
    truthy(str_contains($css, 'glass-aurora'), '缺少可模糊的背景光晕');
    truthy(str_contains($css, '@supports not'), '缺少 backdrop-filter 降级');
    truthy(str_contains($css, 'prefers-reduced-transparency'), '缺少减少透明度偏好');
    truthy(str_contains($css, 'prefers-reduced-motion'), '缺少减少动效偏好');
});

check('控制台样式与脚本逐字来自主题包，无内联兜底', function () use ($dir): void {
    $r = pageKernel('glass')->handle(new Request('GET', '/console', [], adminHeaders()));
    truthy(str_contains($r->contentType(), 'text/html'), '控制台未输出 HTML');
    foreach (['assets/glass.css', 'assets/admin.css', 'assets/admin.js'] as $rel) {
        $text = trim((string) file_get_contents($dir . '/' . $rel));
        truthy($text !== '' && str_contains($r->body(), $text), '资源未逐字注入：' . $rel);
    }
    truthy(str_contains($r->body(), 'data-api="/api/v1"'), '控制台未携带接口前缀');
    truthy(str_contains($r->body(), 'data-layout="admin"'), '后台未使用主题 admin 布局');
    truthy(str_contains($r->body(), 'glass-aurora'), '缺少玻璃背景层');
    truthy(str_contains($r->body(), 'id="glass-output"'), '缺少响应明细容器');
});

check('六个布局均输出玻璃 HTML，且各自使用正确槽位', function (): void {
    $cases = [
        ['/', [], 'site'],
        ['/links', [], 'site'],
        ['/post', ['slug' => 'first-post'], 'post'],
        ['/toolbox', [], 'tool'],
        ['/install', [], 'install'],
        ['/console', [], 'admin'],
    ];
    foreach ($cases as [$path, $query, $slot]) {
        $r = pageKernel('glass')->handle(new Request('GET', $path, $query, adminHeaders()));
        truthy(str_contains($r->contentType(), 'text/html'), $path . ' 未输出 HTML');
        truthy(str_contains($r->body(), 'backdrop-filter'), $path . ' 未带玻璃样式');
        truthy(str_contains($r->body(), 'data-layout="' . $slot . '"'), $path . ' 布局槽位不符');
    }
});

check('无主题时后台退化为纯数据；未登录被拒走主题 deny 布局', function (): void {
    $plain = pageKernel(null)->handle(new Request('GET', '/console', [], adminHeaders()));
    truthy(str_contains($plain->contentType(), 'json'), '无主题时后台未退化为纯数据');
    foreach (['<html', '<style', 'class='] as $needle) {
        truthy(!str_contains(strtolower($plain->body()), $needle), '无主题时出现呈现痕迹：' . $needle);
    }
    $denied = pageKernel('glass')->handle(new Request('GET', '/console', [], []));
    truthy(str_contains($denied->contentType(), 'text/html'), '拒绝页未由主题渲染');
    truthy(str_contains($denied->body(), 'data-layout="deny"'), '未使用主题 deny 布局');
    truthy(str_contains($denied->body(), '403 · Glass'), '缺少主题拒绝页标识');
});

check('主题渲染经安全系统转义，注入标题不会成为标签', function () use ($root): void {
    $k = adminKernel(['theme' => ['active' => 'glass']]);
    $k->hooks()->filter('theme.context', static function (array $ctx): array {
        $ctx['title'] = '<script>alert(1)</script>';
        return $ctx;
    }, 99);
    $r = $k->handle(new Request('GET', '/console', [], adminHeaders()));
    truthy(!str_contains($r->body(), '<script>alert(1)</script>'), '标题未转义');
    truthy(str_contains($r->body(), '&lt;script&gt;'), '未见转义结果');
});

check('Glass 是承载包：不改核心、不增系统层，接口增量归系统层自报', function () use ($root): void {
    truthy(count(glob($root . '/core/*.php') ?: []) + count(glob($root . '/core/*/*.php') ?: []) === 24, 'core/ 文件数被改动');
    truthy(count(glob($root . '/systems/*/system.json') ?: []) === 17, '系统层数量被改动');
    $registry = json_decode((string) file_get_contents($root . '/api/registry.json'), true);
    truthy(count($registry['endpoints'] ?? []) === 69, '接口清单被改动');
    $ops = array_values(array_column(array_filter($registry['endpoints'],
        static fn(array $e): bool => ($e['system'] ?? '') === 'theme'), 'operation'));
    truthy($ops === ['theme.list', 'theme.activate'], '主题接口未全部归 theme 系统层自报');
    // 主题包只消费接口、绝不注册接口（规矩 J）：呈现与能力不混在一个包里。
    foreach (glob($root . '/themes/Glass/*.php') ?: [] as $php) {
        truthy(!str_contains((string) file_get_contents($php), 'registerOperation'),
            '主题包注册了接口：' . basename($php));
    }
    truthy((glob($root . '/pages/*/assets/*') ?: []) === [], '独立页出现资源文件');
});
