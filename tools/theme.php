<?php
declare(strict_types=1);

/**
 * 元主题出厂复核 CLI —— 只读枚举主题清单并校验初始化基线。
 *
 * 运行：php tools/theme.php list     列出全部主题及 meta / surface / family / 布局槽位
 *       php tools/theme.php verify   校验元主题契约（成对 / 同源 / 覆盖面 / 零外链 / 默认 Headless）
 * 退出码：0 = 通过；1 = 契约被破坏。
 *
 * 为什么是纯静态读取：元主题是「随核心出厂」的事实，不依赖运行时是否装配、是否连库。
 * 引导内核反而会把「清单事实」和「装配结果」混在一起，这里刻意不 require bootstrap.php。
 */

$root = dirname(__DIR__);
$command = (string) ($argv[1] ?? 'list');

/** @return array<string, array> */
function theme_manifests(string $root): array
{
    $out = [];
    foreach (glob($root . '/themes/*/theme.json') ?: [] as $file) {
        $meta = json_decode((string) file_get_contents($file), true);
        if (!is_array($meta)) continue;
        $slug = (string) ($meta['slug'] ?? basename(dirname($file)));
        $out[$slug] = ['meta' => $meta, 'dir' => dirname($file)];
    }
    ksort($out);
    return $out;
}

$themes = theme_manifests($root);

if ($command === 'list') {
    echo "=== 出厂主题清单（" . count($themes) . " 款） ===\n";
    printf("  %-14s %-6s %-8s %-10s %s\n", 'slug', 'meta', 'surface', 'family', 'layouts');
    foreach ($themes as $slug => $t) {
        $m = $t['meta'];
        printf(
            "  %-14s %-6s %-8s %-10s %s\n",
            $slug,
            !empty($m['meta']) ? 'yes' : '-',
            (string) ($m['surface'] ?? '-'),
            (string) ($m['family'] ?? '-'),
            implode(',', (array) ($m['layouts'] ?? []))
        );
    }
    $metas = array_filter($themes, static fn(array $t): bool => !empty($t['meta']['meta']));
    echo '元主题（初始化主题）：' . (count($metas) ?: 0) . " 款\n";
    exit(0);
}

if ($command !== 'verify') {
    fwrite(STDERR, "未知命令：{$command}（可用：list / verify）\n");
    exit(1);
}

$fail = [];
$note = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    if (!$ok) $fail[] = $label;
};

echo "=== 元主题出厂契约复核 ===\n";

// 1. 恰好一对 front / back 元主题，且都 enabled
$metas = array_filter($themes, static fn(array $t): bool => !empty($t['meta']['meta']));
$surfaces = [];
foreach ($metas as $slug => $t) {
    $surfaces[(string) ($t['meta']['surface'] ?? '')][] = $slug;
    $note(($t['meta']['enabled'] ?? false) === true, "{$slug} 出厂即启用");
}
$note(isset($surfaces['front']) && count($surfaces['front']) === 1, '前端元主题恰好一款');
$note(isset($surfaces['back']) && count($surfaces['back']) === 1, '后端元主题恰好一款');

// 2. family 一致（同一「磨砂玻璃材质质感」主题类型）
$families = array_unique(array_map(
    static fn(array $t): string => (string) ($t['meta']['family'] ?? ''),
    array_values($metas)
));
$note(count($families) === 1 && ($families[0] ?? '') !== '', '元主题同属一个 family（材质家族一致）');

// 3. 设计系统逐字同源（不是「长得像」，是同一份字节）
$front = $surfaces['front'][0] ?? null;
$back = $surfaces['back'][0] ?? null;
if ($front !== null && $back !== null) {
    $a = (string) file_get_contents($themes[$front]['dir'] . '/assets/glass.css');
    $b = (string) file_get_contents($themes[$back]['dir'] . '/assets/glass.css');
    $note($a !== '' && $a === $b, '两款元主题 glass.css 逐字同源');
}

// 4. 覆盖面：初始化基线要能独立承载全站，六槽位齐全
$required = ['site', 'post', 'admin', 'tool', 'install', 'deny'];
foreach ([$front, $back] as $slug) {
    if ($slug === null) continue;
    $layouts = (array) ($themes[$slug]['meta']['layouts'] ?? []);
    $missing = array_diff($required, $layouts);
    $note($missing === [], "{$slug} 六布局槽位齐全" . ($missing ? '（缺 ' . implode(',', $missing) . '）' : ''));
}

// 5. 零外部依赖：元主题 CSS/JS 不得引用外链 / @import / url(http)
foreach ([$front, $back] as $slug) {
    if ($slug === null) continue;
    $assets = glob($themes[$slug]['dir'] . '/assets/*.{css,js}', GLOB_BRACE) ?: [];
    $bad = [];
    foreach ($assets as $asset) {
        $src = (string) file_get_contents($asset);
        if (preg_match('#https?://|@import|url\(\s*[\'\"]?https?#i', $src)) {
            $bad[] = basename($asset);
        }
    }
    $note($bad === [], "{$slug} 零外部资源" . ($bad ? '（' . implode(',', $bad) . '）' : ''));
}

// 6. 默认 Headless 不被改动：元主题是「开箱可选」，不是「强制启用」
$app = (string) file_get_contents($root . '/config/app.php');
preg_match("#'theme'\s*=>\s*\[\s*'active'\s*=>\s*([^,]+),#", $app, $m);
$note(isset($m[1]) && trim($m[1]) === 'null', 'theme.active 默认仍为 null（Headless 根基未动）');

echo $fail === []
    ? "结果：通过（0 项破坏）\n"
    : "结果：失败（" . count($fail) . " 项）\n";
exit($fail === [] ? 0 : 1);
