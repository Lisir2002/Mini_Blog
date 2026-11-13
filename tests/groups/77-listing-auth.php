<?php
declare(strict_types=1);

/**
 * 77 · 主题清单鉴权对齐与插件单一装配源（v0.61，F4 + F5）
 *
 * F4（theme.list 收紧鉴权 + 清理死权限）：/api/v1/themes 此前 auth=public 且不挂权限点，
 *     向匿名访客暴露全部主题包的 slug / version / 布局面——只读但属部署面信息暴露；
 *     而 permissions.json 声明的 theme.read 从未被任何端点挂载，属「声明即死」的权限。
 *     v0.61 对齐其余读接口形态（如 metric.read）：auth=admin + permission=theme.read。
 * F5（插件单一装配源）：plugin.enabled 配置白名单与 lifecycle 状态机两份真相并存、
 *     可以互相打架。v0.59 已让 package.enabled 对插件 absent 一律拒绝，
 *     白名单成了冗余的第二真相——v0.61 退役：装配唯一真源是 lifecycle 状态机，
 *     不想装载的插件走 lifecycle.deactivate。
 */

use Wx\Core\Http\Request;
use Wx\Core\Kernel;

echo "\n=== 77. 主题清单鉴权对齐与插件单一装配源（F4 + F5）===\n";

$tokens = static function (array $map): Kernel {
    global $root;
    return new Kernel($root, ['api' => ['tokens' => $map]]);
};

$call = static function (Kernel $k, string $path, array $headers = []): array {
    $r = $k->handle(new Request('GET', $path, [], $headers));
    return ['status' => $r->status(), 'env' => json_decode($r->body(), true) ?: []];
};

$adminHeaders = ['Authorization' => 'Bearer g77-admin-token'];
$editorHeaders = ['Authorization' => 'Bearer g77-editor-token'];

// G1 · 未认证 → 401 / 40101：清单不再公开暴露
check('F4·G1 · theme.list 未认证返回 401 / 40101', static function () use ($tokens, $call, $adminHeaders): void {
    $k = $tokens(['g77-admin-token' => ['role' => 'admin', 'username' => 'admin', 'user_id' => 1]]);
    $r = $call($k, '/api/v1/themes');
    truthy($r['status'] === 401, '未认证状态码应为 401，实际 ' . $r['status']);
    truthy(($r['env']['code'] ?? null) === 40101, '未认证错误码应为 40101，实际 ' . json_encode($r['env'] ?? []));
});

// G2 · editor 令牌 → 403：非 admin 角色先于权限点被拒
check('F4·G2 · editor 角色令牌被拒（403），清单不再向低权角色开放', static function () use ($tokens, $call, $editorHeaders): void {
    $k = $tokens([
        'g77-admin-token'  => ['role' => 'admin', 'username' => 'admin', 'user_id' => 1],
        'g77-editor-token' => ['role' => 'editor', 'username' => 'editor', 'user_id' => 2],
    ]);
    $r = $call($k, '/api/v1/themes', $editorHeaders);
    truthy($r['status'] === 403, 'editor 状态码应为 403，实际 ' . $r['status']);
    truthy(in_array($r['env']['code'] ?? null, [40301, 40303], true),
        'editor 错误码应为 40301/40303，实际 ' . json_encode($r['env'] ?? []));
});

// G3 · admin 令牌 → 200：清单结构完整（Headless 基线 + 出厂主题数）
check('F4·G3 · admin 令牌访问 theme.list 返回完整清单', static function () use ($tokens, $call, $adminHeaders): void {
    $k = $tokens(['g77-admin-token' => ['role' => 'admin', 'username' => 'admin', 'user_id' => 1]]);
    $r = $call($k, '/api/v1/themes', $adminHeaders);
    $d = (array) ($r['env']['data'] ?? []);
    truthy($r['status'] === 200 && ($r['env']['code'] ?? null) === 0, 'admin 访问清单不可用');
    truthy(array_key_exists('active', $d) && array_key_exists('headless', $d), '清单缺 active / headless 字段');
    truthy((int) ($d['total'] ?? 0) >= 4, '出厂主题包数量不足 4');
    truthy(is_array($d['items'] ?? null) && count($d['items']) === (int) $d['total'], 'items 与 total 不一致');
});

// G4 · 契约锁：registry 里 theme.list 已收紧，theme.read 不再是死权限
check('F4·G4 · registry 契约锁定：auth=admin + permission=theme.read + 4xx 错误码齐备；theme.read 全仓唯一挂载', static function () use ($root): void {
    $reg = json_decode((string) file_get_contents($root . '/api/registry.json'), true) ?? [];
    $target = null;
    $themeReadMounts = [];
    foreach (($reg['endpoints'] ?? []) as $e) {
        if (($e['operation'] ?? '') === 'theme.list') $target = $e;
        if (($e['permission'] ?? '') === 'theme.read') $themeReadMounts[] = $e['id'] ?? '?';
    }
    truthy($target !== null, 'registry 中找不到 theme.list');
    truthy(($target['auth'] ?? '') === 'admin', 'theme.list auth 应为 admin，实际 ' . ($target['auth'] ?? '?'));
    truthy(($target['permission'] ?? '') === 'theme.read', 'theme.list 应挂载 theme.read');
    $errors = (array) ($target['errors'] ?? []);
    foreach ([40101, 40102, 40301, 40303] as $code) {
        truthy(in_array($code, $errors, true), "错误码表缺 {$code}");
    }
    truthy($themeReadMounts === ['API-THEME-001'],
        'theme.read 应恰好挂载在 API-THEME-001（死权限消除且不重复），实际：' . implode(',', $themeReadMounts));
});

// G5 · 插件单一装配源：配置白名单退役，静态锁死不回流
check('F5·G5 · 插件装配单一真源：config 无 plugin.enabled，PluginSystem 不再读白名单且恒 load(null)', static function () use ($root): void {
    $cfg = (string) file_get_contents($root . '/config/app.php');
    truthy(!str_contains($cfg, "'plugin' => ['enabled'"), 'config/app.php 仍残留 plugin.enabled 白名单');
    truthy(str_contains($cfg, "lifecycle 状态机"), 'config/app.php 缺单一真源注释锚点');

    $src = (string) file_get_contents($root . '/systems/Plugin/PluginSystem.php');
    truthy(!str_contains($src, "config()->get('plugin.enabled'"), 'PluginSystem 仍读取 plugin.enabled 配置');
    truthy(str_contains($src, 'load(null)'), 'PluginSystem 应恒 load(null) 枚举全部包，由 package.enabled 裁决');
});
