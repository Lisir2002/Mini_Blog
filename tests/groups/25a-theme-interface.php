<?php
declare(strict_types=1);

use Wx\Core\Http\Request;
use Wx\Core\Kernel;

echo "\n=== 25a. 主题接口面：清单 / 启用 / 双台账对齐 ===\n";

$local = $root . '/config/local.php';

/** 现场隔离：任何断言都不得把 config/local.php 留给后续分组。 */
$withLocal = static function (callable $fn) use ($local): void {
    $had = is_file($local) ? (string) file_get_contents($local) : null;
    try {
        $fn();
    } finally {
        if ($had === null) {
            if (is_file($local)) @unlink($local);
        } else {
            file_put_contents($local, $had);
        }
    }
};

/** 只带 admin 令牌、不覆盖 theme.active：让 config/local.php 真正参与装配。 */
$themeKernel = static function () use ($root): Kernel {
    return new Kernel($root, ['api' => ['tokens' => [
        'test-admin-token' => ['role' => 'admin', 'username' => 'admin', 'user_id' => 1],
    ]]]);
};

$post = static function (string $path, string $body, array $headers = []) use ($themeKernel): array {
    $r = $themeKernel()->handle(new Request('POST', $path, [], $headers, [], $body));
    return ['status' => $r->status(), 'env' => json_decode($r->body(), true) ?: []];
};

$get = static function (string $path, array $headers = []) use ($themeKernel): array {
    $r = $themeKernel()->handle(new Request('GET', $path, [], $headers));
    return ['status' => $r->status(), 'env' => json_decode($r->body(), true) ?: []];
};

check('theme.list 需 admin + theme.read（v0.61 收紧鉴权），清单逐字段对应出厂 theme.json', function () use ($root, $get): void {
    // 未认证 → 401 / 40101：清单不再公开暴露
    $anon = $get('/api/v1/themes');
    truthy($anon['status'] === 401 && ($anon['env']['code'] ?? null) === 40101, '未认证访问 theme.list 未被拒绝');

    $r = $get('/api/v1/themes', adminHeaders());
    $d = (array) ($r['env']['data'] ?? []);
    truthy($r['status'] === 200 && ($r['env']['code'] ?? null) === 0, '清单接口不可用');
    truthy(array_key_exists('active', $d) && $d['active'] === null
        && ($d['headless'] ?? false) === true, '默认不是 Headless');

    $index = [];
    foreach (glob($root . '/themes/*/theme.json') ?: [] as $file) {
        $m = json_decode((string) file_get_contents($file), true);
        if (is_array($m)) $index[(string) ($m['slug'] ?? '')] = $m;
    }
    truthy(($d['total'] ?? 0) === count($index), '主题数量与出厂清单不符');
    truthy(count($d['items'] ?? []) === count($index), '清单条目数不符');
    foreach ($d['items'] ?? [] as $item) {
        $m = $index[$item['slug'] ?? ''] ?? null;
        truthy($m !== null, '接口返回了不存在的主题：' . ($item['slug'] ?? ''));
        truthy($item['meta'] === !empty($m['meta']), 'meta 字段与清单不符');
        truthy($item['surface'] === (string) ($m['surface'] ?? ''), 'surface 字段与清单不符');
        truthy($item['family'] === (string) ($m['family'] ?? ''), 'family 字段与清单不符');
        truthy($item['layouts'] === array_values((array) ($m['layouts'] ?? [])), 'layouts 与清单不符');
        truthy($item['enabled'] === (($m['enabled'] ?? true) === true), 'enabled 与清单不符');
        truthy($item['active'] === false, 'Headless 下不应有主题被标为启用');
    }
});

check('theme.activate 需 admin：匿名写返回 40101', function () use ($post): void {
    $r = $post('/api/v1/themes/active', '{"slug":"glass"}');
    truthy($r['status'] === 401 && ($r['env']['code'] ?? null) === 40101, '匿名写未被拒绝');
});

check('切换只写 config/local.php，主配置不变，新请求回读到同一启用源', function () use ($root, $post, $get, $withLocal): void {
    $withLocal(function () use ($root, $post, $get): void {
        $r = $post('/api/v1/themes/active', '{"slug":"glass-front"}', adminHeaders());
        $p = (array) ($r['env']['data'] ?? []);
        truthy(($r['env']['code'] ?? null) === 0, '切换失败');
        truthy(($p['active'] ?? null) === 'glass-front' && ($p['changed'] ?? null) === true,
            '未返回目标启用主题');
        truthy(($p['effective'] ?? null) === 'next_request', '未声明生效时机');
        truthy(is_file($root . '/config/local.php'), '未写本地覆盖层');

        $app = (string) file_get_contents($root . '/config/app.php');
        preg_match("#'theme'\s*=>\s*\[\s*'active'\s*=>\s*([^,]+),#", $app, $m);
        truthy(isset($m[1]) && trim($m[1]) === 'null', '带注释的主配置被改动');

        $info = (array) ($get('/api/v1/system/info')['env']['data'] ?? []);
        truthy(($info['theme_active'] ?? null) === 'glass-front', 'system.info 未回读新主题');
        truthy(($info['headless'] ?? true) === false, 'headless 标记未同步');
        $list = (array) ($get('/api/v1/themes', adminHeaders())['env']['data'] ?? []);
        truthy(($list['active'] ?? null) === 'glass-front', 'theme.list 未回读新主题');

        // 幂等：目标与当前一致时不写盘
        $again = $post('/api/v1/themes/active', '{"slug":"glass-front"}', adminHeaders());
        truthy(($again['env']['data']['changed'] ?? null) === false, '重复切换未保持幂等');
    });
});

check('白名单外 slug 被拒；留空 slug 停用主题回到 Headless', function () use ($root, $post, $get, $withLocal): void {
    $withLocal(function () use ($root, $post, $get): void {
        $bad = $post('/api/v1/themes/active', '{"slug":"not-exist"}', adminHeaders());
        truthy($bad['status'] === 404 && ($bad['env']['code'] ?? null) === 40403, '白名单外主题未被拒绝');

        $post('/api/v1/themes/active', '{"slug":"glass"}', adminHeaders());
        $off = $post('/api/v1/themes/active', '{"slug":""}', adminHeaders());
        truthy(($off['env']['code'] ?? null) === 0
            && array_key_exists('active', (array) ($off['env']['data'] ?? []))
            && $off['env']['data']['active'] === null, '停用未回到 Headless');
        $cfg = require $root . '/config/local.php';
        truthy(is_array($cfg) && !isset($cfg['theme']['active']), '本地覆盖层仍残留 theme.active');
        truthy(($get('/api/v1/themes', adminHeaders())['env']['data']['headless'] ?? false) === true, '停用后未回到零呈现');
    });
});

check('双台账对齐：停用当前启用主题被拒，台账与启用态都不变', function () use ($post, $get, $withLocal): void {
    $withLocal(function () use ($post, $get): void {
        $post('/api/v1/themes/active', '{"slug":"glass"}', adminHeaders());
        // 首次盘点会把磁盘单元补登记为 active，使停用走到「当前主题」这道闸门。
        $get('/api/v1/lifecycle/units', adminHeaders());
        $r = $post('/api/v1/lifecycle/deactivate', '{"type":"theme","slug":"glass"}', adminHeaders());
        truthy(($r['env']['code'] ?? null) === 40901, '当前启用主题不应可直接停用');

        $units = (array) ($get('/api/v1/lifecycle/units', adminHeaders())['env']['data']['units'] ?? []);
        $state = null;
        foreach ($units as $u) {
            if (($u['type'] ?? '') === 'theme' && ($u['slug'] ?? '') === 'glass') $state = $u['state'] ?? null;
        }
        truthy($state === 'active', '装卸台账被错误改写');
        truthy(($get('/api/v1/themes', adminHeaders())['env']['data']['active'] ?? null) === 'glass', '启用源被错误改写');
    });
});

check('主题能力归系统层：两个契约在容器内可替换，core/ 仍 24 文件', function () use ($root, $themeKernel): void {
    $k = $themeKernel();
    $k->boot();
    truthy($k->container()->has(\Wx\Systems\Theme\ThemeCatalogInterface::class), '清单契约未绑定');
    truthy($k->container()->has(\Wx\Systems\Theme\ThemeActivationInterface::class), '启用契约未绑定');
    truthy(count(glob($root . '/core/*.php') ?: []) + count(glob($root . '/core/*/*.php') ?: []) === 24,
        'core/ 文件数被改动');
    truthy(count(glob($root . '/systems/*/system.json') ?: []) === 18, '系统层数量被改动');
});
