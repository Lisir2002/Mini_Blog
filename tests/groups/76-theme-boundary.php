<?php
declare(strict_types=1);

/**
 * 76 · 规矩 J 静态强制：主题包只消费、不注册接口（v0.60）
 *
 * 规矩 J（docs/THEMES.md）约定「主题包只消费不注册接口」——主题只负责呈现：
 * 绑渲染器（container->singleton）、经 theme.context 注入身份、经 theme.layouts 自报槽位。
 * 它不得声明路由、不得注册操作/接口，否则即是一条未被代码层强制的提权面。
 *
 * 此前这条边界只是约定（advisory）。本组把它变成 CI 可锁死的静态守卫：
 * 扫 themes/* 下全部 PHP 源码，凡出现任一「注册面」token 即失败。
 *
 * 禁止 token（规矩 J 红线）：
 *   - 'route.resolve' / "route.resolve"   主题不得声明 / 拦截路由
 *   - 'route.access'  / "route.access"    主题不得介入访问裁决
 *   - 'route.response'/ "route.response"  主题不得改写响应门禁
 *   - registerOperation                      主题不得注册 API 操作
 *   - addOperation                           主题不得追加 API 操作
 *
 * 合法主题行为（不被禁止）：container()->singleton()、hooks()->filter('theme.context')、
 * hooks()->filter('theme.layouts')。本守卫对它们零误伤。
 */

echo "\n=== 76. 规矩 J 静态强制：主题包只消费不注册接口 ===\n";

$themeRoot = $root . '/themes';

// 规矩 J 红线：任何主题源码出现即视为越界
$forbidden = [
    "'route.resolve'", '"route.resolve"',
    "'route.access'",  '"route.access"',
    "'route.response'", '"route.response"',
    'registerOperation',
    'addOperation',
];

// 递归收集 themes/* 下所有 PHP 文件
$phpFiles = static function (string $dir): array {
    $out = [];
    if (!is_dir($dir)) return $out;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') $out[] = $f->getPathname();
    }
    return $out;
};

$themeDirs = glob($themeRoot . '/*', GLOB_ONLYDIR);
$discovered = [];
foreach ($themeDirs as $dir) {
    $slug = basename($dir);
    // 优先以主题自报 slug（theme.json）为准，目录名与 slug 大小写/连字符可能不同
    $metaFile = $dir . '/theme.json';
    if (is_file($metaFile)) {
        $meta = json_decode((string) file_get_contents($metaFile), true);
        if (is_array($meta) && isset($meta['slug'])) $slug = (string) $meta['slug'];
    }
    $files = $phpFiles($dir);
    if ($files === []) continue; // 空目录不参与；空主题本身无代码即无风险
    $violations = [];
    foreach ($files as $file) {
        $src = (string) file_get_contents($file);
        $hit = [];
        foreach ($forbidden as $tok) {
            if (str_contains($src, $tok)) $hit[] = $tok;
        }
        if ($hit !== []) $violations[$file] = $hit;
    }
    $discovered[$slug] = ['files' => $files, 'violations' => $violations];
}

// G1 · 基准主题全部被发现（守卫非空，避免静默放行）
check('G1 · 至少发现 4 个基准主题包（bare / bare-html / glass / glass-front）', static function () use ($discovered): void {
    $baseline = ['bare', 'bare-html', 'glass', 'glass-front'];
    $missing = array_diff($baseline, array_keys($discovered));
    truthy($missing === [], '缺少基准主题包：' . implode(', ', $missing));
    truthy(count($discovered) >= 4, '发现的主题包不足 4 个，实际 ' . count($discovered));
});

// G2 · 每个主题包零越界
foreach ($discovered as $slug => $info) {
    check("G2 · 主题「{$slug}」未触碰规矩 J 红线（不声明路由、不注册操作）", static function () use ($slug, $info): void {
        $v = $info['violations'];
        truthy($v === [], "主题 {$slug} 违反规矩 J：" . implode('; ', array_map(
            static fn($f, $ts) => basename($f) . ' → ' . implode('/', $ts),
            array_keys($v), array_values($v)
        )));
    });
}

// G3 · 穷举红线本身：确认每条禁止 token 确实在源码中存在可匹配目标（守卫非空有效）
//      反向锚定：核心 / 系统层确实使用这些 token，说明禁止面是真实存在的注册行为，
//      而非凭空捏造的误杀规则。
check('G3 · 规矩 J 红线是真实存在的注册行为（core / 系统层确有这些 token，故守卫非误杀）', static function () use ($root, $forbidden): void {
    $haystackFiles = array_merge(
        glob($root . '/core/*.php'),
        glob($root . '/systems/*/*.php'),
        glob($root . '/pages/*/*.php')
    );
    $seen = [];
    foreach ($haystackFiles as $file) {
        $src = (string) file_get_contents($file);
        foreach ($forbidden as $tok) {
            if (str_contains($src, $tok)) $seen[$tok] = true;
        }
    }
    // 至少 route.resolve / registerOperation 必须被核心或系统层命中，证明规则有意义
    truthy(isset($seen["'route.resolve'"]) || isset($seen['"route.resolve"']), 'route.resolve 未在任何核心/系统层出现，红线无意义');
    truthy(isset($seen['registerOperation']), 'registerOperation 未在任何核心/系统层出现，红线无意义');
});
