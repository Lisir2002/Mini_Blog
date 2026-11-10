<?php
declare(strict_types=1);

use Wx\Core\Http\Request;
use Wx\Core\Kernel;

echo "\n=== 23b. Glass 后台：数据全部来自既有系统，主题不臆造 ===\n";

$jsPath = $root . '/themes/Glass/assets/admin.js';

check('探针路径逐条对应 api/registry.json 的既有登记', function () use ($root, $jsPath): void {
    $js = (string) file_get_contents($jsPath);
    preg_match_all("#(?:path|probe):\s*'([^']+)'#", $js, $m);
    $paths = array_values(array_unique($m[1]));
    truthy(count($paths) >= 20, '探针过少，守卫可能失效');
    $registry = json_decode((string) file_get_contents($root . '/api/registry.json'), true);
    $known = array_column($registry['endpoints'] ?? [], 'path');
    foreach ($paths as $path) {
        truthy(
            in_array('/api/v1' . $path, $known, true) || in_array($path, $known, true),
            '主题使用了未登记接口：' . $path
        );
    }
});

check('系统层卡片由 /system/layers 的装配顺序驱动，呈现元数据覆盖当前全部层', function () use ($root, $jsPath): void {
    $js = (string) file_get_contents($jsPath);
    truthy(str_contains($js, '/system/layers'), '未使用系统层清单接口');
    truthy(str_contains($js, '.order'), '未读取装配顺序 order');
    truthy(str_contains($js, 'FALLBACK'), '未知系统层缺少回退，新增层会被遗漏');
    preg_match('/var LAYER_META = \{(.*?)\n  \};/s', $js, $block);
    preg_match_all('/^\s{4}([a-z]+):\s*\{/m', $block[1] ?? '', $keys);
    $declared = $keys[1] ?? [];
    truthy(count($declared) >= 18, '呈现元数据覆盖不足');

    $r = (new Kernel($root))->handle(new Request('GET', '/api/v1/system/layers', [], []));
    $order = (array) (json_decode($r->body(), true)['data']['order'] ?? []);
    truthy($order !== [], '系统层清单接口未返回装配顺序');
    foreach ($order as $slug) {
        truthy(in_array($slug, $declared, true), '呈现元数据缺少系统层：' . $slug);
    }
});

check('令牌只存内存，输出走 textContent，不落盘不注入 HTML', function () use ($jsPath): void {
    $js = (string) file_get_contents($jsPath);
    foreach (['localStorage', 'sessionStorage', 'document.cookie', 'innerHTML', 'eval('] as $needle) {
        truthy(!str_contains($js, $needle), '出现不安全用法：' . $needle);
    }
    truthy(str_contains($js, 'var token'), '令牌未限定为内存变量');
    truthy(str_contains($js, 'textContent'), '未使用 textContent 输出');
    truthy(str_contains($js, 'Authorization'), '受保护接口未携带 Bearer 头');
});

check('主题不写 SQL、骨架不硬编码系统层清单', function () use ($root): void {
    $dir = $root . '/themes/Glass';
    $php = '';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $php .= (string) file_get_contents($file->getPathname());
        }
    }
    $upper = strtoupper($php);
    foreach (['SELECT ', 'INSERT INTO', 'CREATE TABLE', 'UPDATE ', 'DELETE FROM'] as $sql) {
        truthy(!str_contains($upper, $sql), '主题出现 SQL：' . $sql);
    }
    $dash = (string) file_get_contents($dir . '/templates/partials/dashboard.php');
    foreach (['log', 'data', 'security', 'content', 'comment', 'attachment'] as $slug) {
        truthy(!str_contains($dash, "'" . $slug . "'"), '骨架硬编码系统层：' . $slug);
    }
    truthy(str_contains($dash, '/api/v1/system/layers'), '骨架未标注清单来源');
});

check('探针按系统分组，并区分公开与受保护接口', function () use ($jsPath): void {
    $js = (string) file_get_contents($jsPath);
    preg_match_all("/group: '([^']+)'/", $js, $m);
    truthy(count(array_unique($m[1] ?? [])) >= 5, '探针分组不足');
    truthy(str_contains($js, 'auth: true'), '未标注受保护接口');
    truthy(str_contains($js, "setAttribute('data-auth'"), '按钮未区分鉴权需求');
    truthy(str_contains($js, "getElementById('glass-token')"), '缺少令牌输入绑定');
});

check('带默认参数的探针在真实内核上返回合法信封', function () use ($root): void {
    $k = new Kernel($root);
    $urls = [
        '/api/v1/content/stats',
        '/api/v1/emojis',
        '/api/v1/comments?post_slug=first-post',
        '/api/v1/attachments?post_slug=first-post',
        '/api/v1/permission/catalog',
        '/api/v1/system/layers',
    ];
    foreach ($urls as $url) {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        $r = $k->handle(new Request('GET', (string) $parts['path'], $query, []));
        $body = json_decode($r->body(), true);
        truthy(is_array($body) && array_key_exists('code', $body), '探针返回非法信封：' . $url);
    }
});
