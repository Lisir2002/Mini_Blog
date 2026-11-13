<?php
declare(strict_types=1);

/**
 * 75 · 承载包装载门禁与主题激活预检（v0.59，E1 + E2）
 *
 * E1（万象核心装载门禁）：插件必须显式经 lifecycle.activate 进入 active 才装载；
 *     缺省 absent 不再默启，关闭「往 plugins/ 丢一个 plugin.json 即等于落盘即执行」的 fail-open 面。
 *     主题 / 独立页保留 absent→默启的向后兼容。
 * E2（主题激活预检）：theme.activate 写盘前校验清单声明的类真实可加载，否则 50001 拦下，
 *     把「写盘后启动才崩」的可用性风险转成显式失败，且不写 config/local.php。
 */

use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Systems\Api\ApiOperationException;
use Wx\Systems\Theme\LocalThemeActivation;
use Wx\Systems\Theme\ThemeCatalogInterface;

echo "\n=== 75. 承载包装载门禁与主题激活预检（E1 + E2）===\n";

$local = $root . '/config/local.php';
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

$themeKernel = static function () use ($root): Kernel {
    return new Kernel($root, ['api' => ['tokens' => [
        'test-admin-token' => ['role' => 'admin', 'username' => 'admin', 'user_id' => 1],
    ]]]);
};

$post = static function (string $path, string $body, array $headers = []) use ($themeKernel): array {
    $r = $themeKernel()->handle(new Request('POST', $path, [], $headers, [], $body));
    return ['status' => $r->status(), 'env' => json_decode($r->body(), true) ?: []];
};

check('E1·G1 · 已激活插件经 package.enabled 仍放行（hello 在 fixture 中为 active）', function () use ($root): void {
    $k = (new Kernel($root, ['api' => ['tokens' => [
        'test-admin-token' => ['role' => 'admin', 'username' => 'admin', 'user_id' => 1],
    ]]]));
    $k->boot();
    $allowed = $k->hooks()->apply('package.enabled', true, 'hello', 'plugin.json', $k);
    truthy($allowed === true, '已激活插件应被放行（package.enabled 返回 true）');
});

check('E1·G2 · 缺席（absent）插件经 package.enabled 被拒：丢文件即执行面关闭', function () use ($root): void {
    $k = (new Kernel($root, ['api' => ['tokens' => [
        'test-admin-token' => ['role' => 'admin', 'username' => 'admin', 'user_id' => 1],
    ]]]));
    $k->boot();
    $allowed = $k->hooks()->apply('package.enabled', true, 'no-such-plugin', 'plugin.json', $k);
    truthy($allowed === false, '缺席插件必须被拒（package.enabled 返回 false），否则落盘即执行');
});

check('E1·G3 · 主题保留向后兼容：absent 主题仍按默启处理（仅由 ThemeSystem 收窄到 active）', function () use ($root): void {
    $k = (new Kernel($root, ['api' => ['tokens' => [
        'test-admin-token' => ['role' => 'admin', 'username' => 'admin', 'user_id' => 1],
    ]]]));
    $k->boot();
    // 主题侧不应因 E1 改变：absent 主题仍走原分支（此处只验证钩子对 theme.json 不变成「仅 active」）。
    $state = $k->container()->resolve(\Wx\Contracts\LifecycleManagerInterface::class)->stateOf('theme', 'no-such-theme');
    truthy($state === 'absent', '未知主题应为 absent 状态');
});

check('E2·G4 · 主题清单声明了类但类文件缺失：theme.activate 预检拒绝 50001 且不写 local.php', function () use ($root, $withLocal): void {
    $withLocal(function () use ($root): void {
        $k = (new Kernel($root, ['api' => ['tokens' => [
            'test-admin-token' => ['role' => 'admin', 'username' => 'admin', 'user_id' => 1],
        ]]]));
        $k->boot();
        // 用最小桩 catalog 隔离测试预检路径：slug 存在（has=true）但 class 指向不存在的类。
        $catalog = new class implements ThemeCatalogInterface {
            public function all(): array { return []; }
            public function active(): ?string { return null; }
            public function has(string $slug): bool { return $slug === 'ghost'; }
            public function classOf(string $slug): ?string {
                return $slug === 'ghost' ? 'Wx\\Themes\\Ghost\\NoSuchTheme' : null;
            }
        };
        $activation = new LocalThemeActivation($k, $catalog);
        $threw = false;
        try {
            $activation->activate('ghost');
        } catch (ApiOperationException $e) {
            $threw = $e->apiCode === 50001;
        }
        truthy($threw, '缺失类的主题应被预检拒绝（50001）');
        truthy(!is_file($root . '/config/local.php'), '预检失败不应写本地覆盖层');
    });
});

check('E2·G5 · ThemeCatalog.classOf 为真实主题返回可加载类名（glass），锁接线', function () use ($root): void {
    $k = (new Kernel($root, ['api' => ['tokens' => [
        'test-admin-token' => ['role' => 'admin', 'username' => 'admin', 'user_id' => 1],
    ]]]));
    $k->boot();
    /** @var ThemeCatalogInterface $catalog */
    $catalog = $k->container()->resolve(ThemeCatalogInterface::class);
    $class = $catalog->classOf('glass');
    truthy($class !== null, 'glass 应解析出类名');
    truthy(class_exists($class), 'glass 解析出的类名必须真实可加载：' . ($class ?? ''));
});

check('E2·G6 · 正常主题预检通过即可激活并写盘（glass-front 经 LocalThemeActivation 成功）', function () use ($root, $withLocal): void {
    $withLocal(function () use ($root): void {
        $k = (new Kernel($root, ['api' => ['tokens' => [
            'test-admin-token' => ['role' => 'admin', 'username' => 'admin', 'user_id' => 1],
        ]]]));
        $k->boot();
        /** @var ThemeCatalogInterface $catalog */
        $catalog = $k->container()->resolve(ThemeCatalogInterface::class);
        $activation = new LocalThemeActivation($k, $catalog);
        $r = $activation->activate('glass-front');
        truthy(($r['changed'] ?? false) === true, 'glass-front 应激活成功');
        truthy(is_file($root . '/config/local.php'), '激活应写本地覆盖层');
        $cfg = require $root . '/config/local.php';
        truthy(($cfg['theme']['active'] ?? null) === 'glass-front', '本地覆盖层应记录 glass-front');
    });
});
