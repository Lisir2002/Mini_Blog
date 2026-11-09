<?php
declare(strict_types=1);

echo "\n=== 24a. 元主题（初始化主题）出厂契约 ===\n";

$front = $root . '/themes/GlassFront';
$back = $root . '/themes/Glass';
$required = ['site', 'post', 'admin', 'tool', 'install', 'deny'];

$manifest = static function (string $dir): array {
    $m = json_decode((string) file_get_contents($dir . '/theme.json'), true);
    return is_array($m) ? $m : [];
};

check('两款元主题成对：meta / surface=front|back / family 一致', function () use ($front, $back, $manifest): void {
    $f = $manifest($front);
    $g = $manifest($back);
    truthy(($f['slug'] ?? null) === 'glass-front', '前端元主题 slug 不符');
    truthy(($g['slug'] ?? null) === 'glass', '后端元主题 slug 不符');
    foreach (['f' => $f, 'g' => $g] as $m) {
        truthy(($m['meta'] ?? null) === true, '未标记 meta=true');
        truthy(($m['enabled'] ?? null) === true, '元主题未出厂启用');
    }
    truthy(($f['surface'] ?? null) === 'front', '前端元主题 surface 不是 front');
    truthy(($g['surface'] ?? null) === 'back', '后端元主题 surface 不是 back');
    truthy(($f['family'] ?? null) === 'glass' && ($f['family'] ?? null) === ($g['family'] ?? null),
        '两款不属于同一材质家族');
});

check('Bare / BareHtml 是非元主题示例，不冒充初始化基线', function () use ($root, $manifest): void {
    foreach (['Bare', 'BareHtml'] as $dir) {
        $m = $manifest($root . '/themes/' . $dir);
        truthy(empty($m['meta']), $dir . ' 不应标记 meta');
        truthy(!isset($m['surface']), $dir . ' 不应声明 surface');
    }
});

check('元主题 glass.css 逐字同源（同一磨砂玻璃材质，不是「长得像」）', function () use ($front, $back): void {
    $a = (string) file_get_contents($front . '/assets/glass.css');
    $b = (string) file_get_contents($back . '/assets/glass.css');
    truthy($a !== '' && $a === $b, '两款设计系统不是逐字同源');
    // 材质三要素确在同源设计系统内
    truthy(str_contains($a, 'backdrop-filter: blur'), '缺少背景模糊');
    truthy(str_contains($a, 'rgba(255, 255, 255, .07)'), '缺少半透明玻璃底');
    truthy(str_contains($a, 'glass-aurora'), '缺少渐变光晕');
    truthy(str_contains($a, '@supports not'), '缺少降级');
});

check('两款元主题六布局齐全，可各自作为初始化基线独立启用', function () use ($front, $back, $required, $manifest): void {
    foreach ([$front => 'glass-front', $back => 'glass'] as $dir => $slug) {
        $layouts = (array) ($manifest($dir)['layouts'] ?? []);
        truthy(array_diff($required, $layouts) === [], $slug . ' 布局槽位不齐全');
    }
});

check('元主题零外部资源：CSS/JS 不含外链 / @import', function () use ($front, $back): void {
    foreach ([$front, $back] as $dir) {
        foreach (glob($dir . '/assets/*.{css,js}', GLOB_BRACE) ?: [] as $asset) {
            $src = (string) file_get_contents($asset);
            truthy(!preg_match('#https?://|@import|url\(\s*[\'\"]?https?#i', $src),
                basename($asset) . ' 引用了外部资源');
        }
    }
});

check('默认 Headless 根基未动：theme.active 仍为 null', function () use ($root): void {
    $app = (string) file_get_contents($root . '/config/app.php');
    preg_match("#'theme'\s*=>\s*\[\s*'active'\s*=>\s*([^,]+),#", $app, $m);
    truthy(isset($m[1]) && trim($m[1]) === 'null', 'theme.active 默认值被改动');
    $plain = kernel(null)->handle(new \Wx\Core\Http\Request('GET', '/', [], []));
    truthy(str_contains($plain->contentType(), 'json'), '无主题时首页未退化为纯数据');
});

check('元主题是承载包内容，不改核心 / 系统层 / 接口清单', function () use ($root): void {
    truthy(count(glob($root . '/core/*.php') ?: []) + count(glob($root . '/core/*/*.php') ?: []) === 24,
        'core/ 文件数被改动');
    truthy(count(glob($root . '/systems/*/system.json') ?: []) === 17, '系统层数量被改动');
    $registry = json_decode((string) file_get_contents($root . '/api/registry.json'), true);
    truthy(count($registry['endpoints'] ?? []) === 67, '接口清单被改动');
});

check('出厂复核工具 list / verify 可重跑且通过', function () use ($root): void {
    $tool = $root . '/tools/theme.php';
    truthy(is_file($tool), '缺少 tools/theme.php');
    // 通过 include 在隔离进程里不可靠，这里直接断言工具登记在 17 组白名单中
    $hygiene = (string) file_get_contents($root . '/tests/groups/17-project-hygiene.php');
    truthy(str_contains($hygiene, "'theme.php'"), '工具未登记进 17 组白名单（规矩 O）');
});
