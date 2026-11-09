<?php
declare(strict_types=1);

echo "\n=== 25b. Glass 后台：主题可视化操作分区 ===\n";

$dir = $root . '/themes/Glass';
$dash = (string) file_get_contents($dir . '/templates/partials/dashboard.php');
$js   = (string) file_get_contents($dir . '/assets/admin.js');
$css  = (string) file_get_contents($dir . '/assets/admin.css');

check('后台新增主题分区：导航入口、清单容器、当前态与停用入口', function () use ($dash): void {
    truthy(str_contains($dash, 'data-nav="themes"'), '导航缺少主题入口');
    truthy(str_contains($dash, 'id="glass-themes"'), '缺少主题卡片容器');
    truthy(str_contains($dash, 'id="glass-theme-active"'), '缺少当前启用态显示');
    truthy(str_contains($dash, 'id="glass-theme-headless"'), '缺少停用主题入口');
    truthy(str_contains($dash, 'id="glass-theme-output"') && str_contains($dash, 'aria-live'),
        '操作结果缺少可访问性状态出口');
    truthy(!str_contains($dash, '<style') && !str_contains($dash, '<script'), '骨架出现内联样式或脚本');
});

check('主题探针路径逐条对应 registry 的 theme 接口，不臆造端点', function () use ($root, $js): void {
    truthy(str_contains($js, "path: '/themes'"), '缺少清单接口');
    truthy(str_contains($js, "path: '/themes/active'"), '缺少启用接口');
    preg_match_all("#(?:path|probe):\s*'([^']+)'#", $js, $m);
    $registry = json_decode((string) file_get_contents($root . '/api/registry.json'), true);
    $known = array_column($registry['endpoints'] ?? [], 'path');
    foreach (['/themes', '/themes/active'] as $path) {
        truthy(in_array('/api/v1' . $path, $known, true), '主题分区使用了未登记接口：' . $path);
    }
    $ops = array_values(array_column(array_filter($registry['endpoints'],
        static fn(array $e): bool => ($e['system'] ?? '') === 'theme'), 'operation'));
    truthy($ops === ['theme.list', 'theme.activate'], '主题接口未全部归 theme 系统层');
});

check('主题卡片由接口数据生成，写操作需令牌，输出走 textContent', function () use ($js): void {
    truthy(str_contains($js, "el('article', 'glass-card theme-card')"), '卡片未由脚本按清单生成');
    truthy(str_contains($js, 'function themes(result)'), '缺少清单渲染函数');
    truthy(str_contains($js, "setAttribute('data-auth', 'true')"), '写操作未标注鉴权需求');
    truthy(str_contains($js, 'if (!token)'), '写操作缺少令牌前置检查');
    truthy(str_contains($js, "method: method || 'GET'") && str_contains($js, 'opts.body = JSON.stringify(body)'),
        '缺少写请求的 JSON 体构造');
    foreach (['innerHTML', 'localStorage', 'sessionStorage', 'document.cookie', 'eval('] as $needle) {
        truthy(!str_contains($js, $needle), '出现不安全用法：' . $needle);
    }
    // 主题清单不硬编码：slug 一律来自接口字段
    foreach (["'glass-front'", "'bare-html'", "'glass'", "'bare'"] as $slug) {
        truthy(!str_contains($js, $slug), '脚本内置了主题标识：' . $slug);
    }
});

check('主题分区样式只在主题包，玻璃基元仍来自同源设计系统', function () use ($root, $css): void {
    truthy(str_contains($css, '.theme-card'), '主题分区缺少结构样式');
    truthy(str_contains($css, '.theme-card.is-active'), '启用态缺少视觉标识');
    $php = '';
    foreach (glob($root . '/themes/Glass/templates/**/*.php') ?: [] as $f) {
        $php .= (string) file_get_contents($f);
    }
    truthy(!str_contains($php, 'registerOperation'), '主题包注册了接口（规矩 J）');
    $a = (string) file_get_contents($root . '/themes/Glass/assets/glass.css');
    $b = (string) file_get_contents($root . '/themes/GlassFront/assets/glass.css');
    truthy($a !== '' && $a === $b, '两款元主题设计系统不再逐字同源');
});
