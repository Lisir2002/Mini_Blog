<?php
declare(strict_types=1);

use Wx\Contracts\ContentRepositoryInterface;
use Wx\Core\HookBus;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Systems\Lifecycle\Fs;
use Wx\Systems\Lifecycle\LifecycleException;
use Wx\Systems\Lifecycle\LifecycleManager;

echo "\n=== 11. 承载包：布局权归主题 / 可卸载 / 签名验签 / 命名定案 ===\n";

check('bare-html 主题输出 HTML，但零 CSS / 零 class / 零脚本', function () use ($root): void {
    $r = kernel('bare-html')->handle(new Request('GET', '/'));
    truthy(str_contains($r->contentType(), 'text/html'), '未输出 HTML');
    truthy(str_contains($r->body(), '<h1>'), '缺少语义标题');
    $body = strtolower($r->body());
    foreach (['<style', 'class=', 'stylesheet', '<script', '<link'] as $needle) {
        truthy(!str_contains($body, $needle), '裸 HTML 主题出现样式痕迹：' . $needle);
    }
    truthy(count(glob($root . '/themes/BareHtml/style.css') ?: []) === 0, 'bare-html 不应持有 CSS 文件');
});

check('规矩 J：独立页零模板零 CSS/JS，后台布局与样式必须来自主题包', function () use ($root): void {
    truthy((glob($root . '/pages/*/assets/*') ?: []) === [], '独立页仍持有 assets 资源');
    truthy((glob($root . '/pages/*/templates/*.php') ?: []) === [], '独立页仍持有 HTML 模板');

    $siteCss  = trim((string) file_get_contents($root . '/themes/Bare/style.css'));
    $adminCss = trim((string) file_get_contents($root . '/themes/Bare/assets/admin.css'));
    truthy($siteCss !== '' && $adminCss !== '', '守卫失效：主题样式文件为空');

    // 无主题：连后台也必须零标签、零样式，退化为纯数据
    foreach (['/console', '/toolbox', '/install'] as $path) {
        $r = pageKernel()->handle(new Request('GET', $path, [], adminHeaders()));
        truthy(str_contains($r->contentType(), 'json'), $path . ' 无主题时未退化为纯数据');
        $body = strtolower($r->body());
        foreach (['<html', '<style', '<script', 'class='] as $needle) {
            truthy(!str_contains($body, $needle), $path . ' 无主题时出现呈现痕迹：' . $needle);
        }
    }

    // 有主题：后台走主题自报的 admin/tool/install 布局，样式逐字来自主题目录
    foreach (['/console', '/toolbox', '/install'] as $path) {
        $r = pageKernel('bare')->handle(new Request('GET', $path, [], adminHeaders()));
        truthy(str_contains($r->contentType(), 'text/html'), $path . ' 未输出 HTML');
        truthy(!str_contains($r->body(), $siteCss), $path . ' 被站点 site 布局样式覆盖');
    }
    $console = pageKernel('bare')->handle(new Request('GET', '/console', [], adminHeaders()));
    truthy(str_contains($console->body(), $adminCss), '控制台样式并非来自主题包');
    truthy(str_contains($console->body(), 'data-api="/api/v1"'), '控制台未携带接口前缀');
    truthy(str_contains($console->body(), 'data-layout="admin"'), '后台未使用主题 admin 布局');
});

check('主题未提供某布局时该页退化为纯数据，框架绝不兜底输出标签', function (): void {
    foreach (['/console', '/toolbox', '/install'] as $path) {
        $r = pageKernel('bare-html')->handle(new Request('GET', $path, [], adminHeaders()));
        truthy(str_contains($r->contentType(), 'json'), $path . ' 主题缺布局时未退化为纯数据');
        truthy(!str_contains(strtolower($r->body()), '<html'), $path . ' 出现兜底 HTML');
    }
});

check('布局槽位由清单声明，主题通过 theme.layouts 自报可用槽位', function () use ($root): void {
    $k = kernel('bare');
    $k->boot();
    $declared = (array) $k->hooks()->apply('theme.layouts', []);
    foreach (['site', 'admin', 'tool', 'install'] as $slot) {
        truthy(in_array($slot, $declared, true), '主题未自报布局槽位：' . $slot);
    }
    $console = json_decode((string) file_get_contents($root . '/pages/Console/page.json'), true);
    truthy(($console['layout'] ?? null) === 'admin', '后台未在清单声明 admin 布局');
    truthy(!isset($console['standalone']), '独立页仍保留绕过主题的 standalone 声明');
});

check('独立页可白名单卸载：只启用 links 时 /console 不再被接管', function () use ($root): void {
    $k = new Kernel($root, ['page' => ['enabled' => ['links']], 'theme' => ['active' => null]]);
    $r = $k->handle(new Request('GET', '/console'));
    truthy(!str_contains($r->body(), '站点控制台'), '未装载的独立页不应渲染控制台');
    truthy(!str_contains(strtolower($r->contentType()), 'html'), '未装载的独立页不应输出 HTML');
});

check('首页文章标题来自内容系统契约，而非核心硬编码', function () use ($root): void {
    $k = kernel(null);
    $k->boot();
    $repo = $k->container()->resolve(ContentRepositoryInterface::class);
    $titles = array_map(static fn(array $p): string => (string) $p['title'], $repo->list(['limit' => 10])['items']);
    $r = $k->handle(new Request('GET', '/'));
    $items = json_decode($r->body(), true)['items'] ?? [];
    truthy($titles !== [] && $items[0] === $titles[0], '首页首条标题与内容仓库不一致');
    $source = (string) file_get_contents($root . '/pages/Home/HomePage.php');
    truthy(str_contains($source, 'ContentRepositoryInterface'), '首页包未通过契约取数据');
});

check('包签名验签：正确签名通过、篡改验签失败、强制缺签名被拒', function (): void {
    $tmp = sys_get_temp_dir() . '/wx-sig-' . bin2hex(random_bytes(4));
    mkdir($tmp . '/plugins', 0775, true);
    mkdir($tmp . '/storage/packages', 0775, true);
    $m = new LifecycleManager($tmp, new HookBus(), [
        'signature_secret' => 's3cr3t', 'require_signature' => true,
    ]);
    try {
        $src = $tmp . '/storage/packages/signed';
        makeSourcePackage($src, '1.0.0', [], false, 'signed');
        signPackage($src, 's3cr3t');
        truthy($m->install('plugin', $src, ['slug' => 'sig-ok'])['state'] === 'installed', '正确签名应通过');

        file_put_contents($src . '/Demo.php', "<?php\n// tampered\n");
        file_put_contents($src . '/manifest.sha256', json_encode(Fs::hashDir($src), JSON_UNESCAPED_SLASHES));
        $threw = false;
        try { $m->install('plugin', $src, ['slug' => 'sig-bad']); }
        catch (LifecycleException $e) { $threw = $e->apiCode === 42201 && str_contains($e->getMessage(), '签名'); }
        truthy($threw, '篡改后应验签失败');

        unlink($src . '/signature.json');
        $threw = false;
        try { $m->install('plugin', $src, ['slug' => 'sig-none']); }
        catch (LifecycleException $e) { $threw = $e->apiCode === 42201 && str_contains($e->getMessage(), 'signature.json'); }
        truthy($threw, 'require_signature=true 时缺签名应被拒绝');
    } finally {
        Fs::removeDir($tmp);
    }
});

check('命名 / 版本 / PHP 最低版本已定案（不再待定）', function () use ($root): void {
    $v = json_decode((string) file_get_contents($root . '/core/version.json'), true);
    truthy(($v['name'] ?? null) === 'miniblog-core', '正式名未定案');
    truthy(($v['product'] ?? null) === 'Mini Blog', '产品名未定案');
    truthy(($v['namespace'] ?? null) === 'Wx\\', '命名空间未定案');
    truthy(($v['version'] ?? null) === '0.37.0', '版本未定案');
    truthy(($v['requires_php'] ?? null) === '>=8.1', 'PHP 最低版本未定案为 8.1+');
    truthy(PHP_VERSION_ID >= 80100, '当前 PHP 低于定案要求');
});
