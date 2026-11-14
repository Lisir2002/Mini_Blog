<?php
declare(strict_types=1);

/**
 * 78 · 启动期完整性复验（F6 收口）与默认档位 fail-closed（v0.64，A1 + A2）
 *
 * A1：承载包「安装时验签、运行时裸奔」的缺口收口。落点是 package.enabled
 *     过滤钩子（priority 20，排在装卸状态裁决之后、new $class() 之前），
 *     core/ 24 文件零改动。三态：有基线→复验、无基线→放行但如实留痕、
 *     篡改→拒装载。基线由 install/upgrade/rollback 落位后写入台账。
 * A2：源包完整性清单默认强制 + 清单外文件检测（此前只遍历清单内键，
 *     往源包塞一个清单没登记的 PHP 文件不会被发现）。
 *
 * 边界（守卫只锁守得住的部分，不夸大）：基线在站点可写的台账里，密钥非空时
 * 带 HMAC；secret 为空时只做 SHA-256 比对，挡「改包不改台账」，不挡「两者同改」。
 */

use Wx\Core\HookBus;
use Wx\Systems\Lifecycle\BootIntegrity;
use Wx\Systems\Lifecycle\Fs;
use Wx\Systems\Lifecycle\LifecycleException;
use Wx\Systems\Lifecycle\LifecycleManager;
use Wx\Systems\Lifecycle\PackageVerifier;

echo "\n=== 78. 启动期完整性复验与默认档位 fail-closed（A1 + A2）===\n";

/** 造一个有载荷的隔离站点，返回 [站点根, 包目录, 台账记录, 密钥]。 */
$makeSite = static function (string $tag, string $secret): array {
    $dir = sys_get_temp_dir() . '/wx-bi-' . $tag . '-' . bin2hex(random_bytes(4));
    mkdir($dir . '/plugins/demo', 0775, true);
    file_put_contents($dir . '/plugins/demo/plugin.json', json_encode(
        ['slug' => 'demo', 'version' => '1.0.0', 'class' => 'Wx\\Plugins\\demo\\DemoPlugin'],
        JSON_UNESCAPED_UNICODE
    ));
    file_put_contents($dir . '/plugins/demo/Payload.php', "<?php\n// pristine payload\n");
    $record = [
        'type' => 'plugin', 'slug' => 'demo', 'state' => 'active',
        'path' => 'plugins/demo',
        'integrity' => Fs::baseline($dir . '/plugins/demo', $secret),
    ];
    return [$dir, $dir . '/plugins/demo', $record, $secret];
};

/** 订阅三个完整性事件，返回带 bus / events 属性的探针对象（对象保持引用语义）。 */
$makeBus = static function (): object {
    $bus = new HookBus();
    $probe = (object) ['bus' => $bus, 'events' => []];
    foreach (['package.integrity.verified', 'package.integrity.rejected', 'package.integrity.unmanaged'] as $tag) {
        $bus->on($tag, static function (...$args) use ($probe, $tag): void {
            $probe->events[] = ['tag' => $tag, 'args' => $args];
        });
    }
    return $probe;
};

check('A1·G1 · 落位基线一致时放行，并广播 verified（不静默）', function () use ($makeSite, $makeBus): void {
    [$dir, , $record, $secret] = $makeSite('ok', 's3cr3t');
    try {
        $probe = $makeBus();
        $bus = $probe->bus; $events = &$probe->events;
        $bi = new BootIntegrity($dir, $bus, ['signature_secret' => $secret], ['plugin:demo' => $record]);
        truthy($bi->guard(true, 'demo', 'plugin.json') === true, '基线一致应放行');
        truthy(count(array_filter($events, static fn(array $e): bool =>
            $e['tag'] === 'package.integrity.verified')) === 1, '应广播一次 verified 留痕');
    } finally {
        Fs::removeDir($dir);
    }
});

check('A1·G2 · 落位后载荷被篡改 → 拒装载（fail-closed）并广播 rejected', function () use ($makeSite, $makeBus): void {
    [$dir, $pkgDir, $record, $secret] = $makeSite('tamper', 's3cr3t');
    try {
        file_put_contents($pkgDir . '/Payload.php', "<?php\n// injected webshell\n");
        $probe = $makeBus();
        $bus = $probe->bus; $events = &$probe->events;
        // 篡改后用新实例：真实部署里每次请求也是新内核 / 新门禁实例。
        $bi = new BootIntegrity($dir, $bus, ['signature_secret' => $secret], ['plugin:demo' => $record]);
        truthy($bi->guard(true, 'demo', 'plugin.json') === false, '载荷篡改必须拒装载');
        $hits = array_values(array_filter($events, static fn(array $e): bool =>
            $e['tag'] === 'package.integrity.rejected'));
        truthy(count($hits) === 1 && ($hits[0]['args'][1] ?? '') === 'digest_mismatch',
            'rejected 事件须带 digest_mismatch 原因');
    } finally {
        Fs::removeDir($dir);
    }
});

check('A1·G3 · 出厂 / 历史包无基线 → 放行但只广播 unmanaged，不冒充已验', function () use ($makeSite, $makeBus): void {
    [$dir, , $record] = $makeSite('unmanaged', '');
    try {
        unset($record['integrity']); // 出厂包：reconcile 只登记状态，无落位基线
        $probe = $makeBus();
        $bus = $probe->bus; $events = &$probe->events;
        $bi = new BootIntegrity($dir, $bus, [], ['plugin:demo' => $record]);
        truthy($bi->guard(true, 'demo', 'plugin.json') === true, '出厂包必须放行，否则整站启动即崩');
        $tags = array_column($events, 'tag');
        truthy(in_array('package.integrity.unmanaged', $tags, true), '无基线须如实留痕');
        truthy(!in_array('package.integrity.verified', $tags, true), '无基线不得广播 verified（不假装验过）');
    } finally {
        Fs::removeDir($dir);
    }
});

check('A1·G4 · 基线 HMAC：只改台账 digest 仍被拒；有密钥却无 mac 的旧基线被拒', function () use ($makeSite, $makeBus): void {
    [$dir, , $record, $secret] = $makeSite('mac', 's3cr3t');
    try {
        // (1) 台账 digest 被改成别的合法摘要（包没动）→ digest 不匹配即拒
        $tampered = $record;
        $tampered['integrity']['digest'] = str_repeat('0', 64);
        $probe1 = $makeBus();
        $bi = new BootIntegrity($dir, $probe1->bus, ['signature_secret' => $secret], ['plugin:demo' => $tampered]);
        truthy($bi->guard(true, 'demo', 'plugin.json') === false, '只改台账也必须拒装载');

        // (2) 站点现在配了密钥，基线却是无密钥时代写的 → baseline_unsigned
        $old = $record;
        unset($old['integrity']['mac']);
        $probe2 = $makeBus();
        $bus2 = $probe2->bus; $events2 = &$probe2->events;
        $bi2 = new BootIntegrity($dir, $bus2, ['signature_secret' => $secret], ['plugin:demo' => $old]);
        truthy($bi2->guard(true, 'demo', 'plugin.json') === false, '旧无 HMAC 基线不得冒充受保护');
        $hits = array_values(array_filter($events2, static fn(array $e): bool =>
            $e['tag'] === 'package.integrity.rejected'));
        truthy(($hits[0]['args'][1] ?? '') === 'baseline_unsigned', '原因须为 baseline_unsigned');
    } finally {
        Fs::removeDir($dir);
    }
});

check('A1·G5 · 应急开关 boot_integrity=false 放行篡改包，但不广播 verified', function () use ($makeSite, $makeBus): void {
    [$dir, $pkgDir, $record, $secret] = $makeSite('off', 's3cr3t');
    try {
        file_put_contents($pkgDir . '/Payload.php', "<?php\n// tampered under maintenance\n");
        $probe = $makeBus();
        $bus = $probe->bus; $events = &$probe->events;
        $bi = new BootIntegrity($dir, $bus, ['signature_secret' => $secret, 'boot_integrity' => false], ['plugin:demo' => $record]);
        truthy($bi->guard(true, 'demo', 'plugin.json') === true, '显式关闭后应放行');
        truthy(!in_array('package.integrity.verified', array_column($events, 'tag'), true),
            '关闭态不得广播 verified（避免误读为已验）');
    } finally {
        Fs::removeDir($dir);
    }
});

check('A1·G6 · 装卸链路真写入基线：install 后台账 integrity 含落位产物摘要', function (): void {
    $dir = sys_get_temp_dir() . '/wx-bi-install-' . bin2hex(random_bytes(4));
    mkdir($dir . '/plugins', 0775, true);
    mkdir($dir . '/storage/packages', 0775, true);
    try {
        $src = $dir . '/storage/packages/demo';
        makeSourcePackage($src, '1.0.0', [], true); // 带 on_install 钩子 → 会写 installed.marker
        $m = new LifecycleManager($dir, new HookBus(),
            ['require_checksum' => true, 'signature_secret' => 's3cr3t']);
        $m->install('plugin', $src, ['slug' => 'demo']);
        $record = $m->integrityRecords()['plugin:demo'] ?? [];
        $baseline = $record['integrity'] ?? null;
        truthy(is_array($baseline) && ($baseline['algo'] ?? '') === 'sha256', 'install 须登记 SHA-256 基线');
        truthy(isset($baseline['mac']), '密钥非空时基线须带 HMAC');
        $fresh = Fs::baseline($dir . '/plugins/demo', 's3cr3t');
        truthy($baseline['digest'] === $fresh['digest'],
            '基线须以「落位后」目录为准（含 on_install 产物），否则启动即误拒');
    } finally {
        Fs::removeDir($dir);
    }
});

check('A2·G1 · config/app.php 默认档位 fail-closed（清单 / 签名强制 + 启动复验开）', function () use ($root): void {
    $cfg = require $root . '/config/app.php';
    $lc = (array) ($cfg['lifecycle'] ?? []);
    truthy(($lc['require_checksum'] ?? null) === true, '默认须强制完整性清单');
    truthy(($lc['require_signature'] ?? null) === true, '默认须强制签名（无信任根不收外部包）');
    truthy(($lc['signature_secret'] ?? 'x') === '', '默认不得内置密钥（生产由环境注入）');
    truthy(($lc['boot_integrity'] ?? null) === true, '默认须开启启动期复验');
});

check('A2·G2 · 清单外塞一个 PHP 文件 → assertIntegrity 以 42201 拒绝', function (): void {
    $dir = sys_get_temp_dir() . '/wx-bi-extra-' . bin2hex(random_bytes(4));
    mkdir($dir, 0775, true);
    try {
        file_put_contents($dir . '/plugin.json', '{"slug":"demo"}');
        file_put_contents($dir . '/Payload.php', "<?php\n// legit\n");
        // 清单在塞入 extra 之前生成：只登记两个文件
        file_put_contents($dir . '/manifest.sha256', json_encode(Fs::hashDir($dir), JSON_UNESCAPED_SLASHES));
        file_put_contents($dir . '/webshell.php', "<?php\n// 清单外载荷\n");
        $v = new PackageVerifier($dir, new HookBus(), ['require_checksum' => true]);
        $threw = false;
        try {
            $v->assertIntegrity($dir);
        } catch (LifecycleException $e) {
            $threw = $e->apiCode === 42201 && str_contains($e->getMessage(), '清单外文件');
        }
        truthy($threw, '清单外文件必须被 42201 拒绝（此前只遍历清单内键会漏）');
    } finally {
        Fs::removeDir($dir);
    }
});

check('A2·G3 · 不误伤：清单完整时通过；verify() 对落位后新增文件仍判 ok', function (): void {
    $dir = sys_get_temp_dir() . '/wx-bi-clean-' . bin2hex(random_bytes(4));
    mkdir($dir, 0775, true);
    try {
        file_put_contents($dir . '/plugin.json', '{"slug":"demo"}');
        file_put_contents($dir . '/Payload.php', "<?php\n// legit\n");
        file_put_contents($dir . '/manifest.sha256', json_encode(Fs::hashDir($dir), JSON_UNESCAPED_SLASHES));
        $v = new PackageVerifier($dir, new HookBus(), ['require_checksum' => true]);
        $v->assertIntegrity($dir); // 不抛即通过
        // 运行期 / on_install 产物是合法的：verify() 是落位后探查，不做清单外检测
        file_put_contents($dir . '/installed.marker', '1.0.0');
        truthy($v->verify($dir)['ok'] === true,
            '落位后新增文件不得被 verify() 误判为篡改');
    } finally {
        Fs::removeDir($dir);
    }
});

/**
 * 真实 boot 集成：造一个带落位基线的插件包放进隔离站点，
 * 用 Kernel::boot() 走完整装配链（PackageLoader.load → package.enabled → new $class）。
 * 正例：基线一致 → 类被实例化（plugin.loaded 留痕）。
 * 反例：篡改载荷 → guard 返回 false → 包被跳过、类不被实例化。
 */
$bootSite = static function (): array {
    $dir = tempSite('bij');
    $pkg = $dir . '/plugins/bidemo';
    mkdir($pkg, 0775, true);
    file_put_contents($pkg . '/plugin.json', json_encode([
        'slug' => 'bidemo', 'version' => '1.0.0', 'enabled' => true,
        'class' => 'Wx\\Plugins\\bidemo\\BidemoPlugin',
    ], JSON_UNESCAPED_UNICODE));
    file_put_contents($pkg . '/BidemoPlugin.php',
        "<?php\nnamespace Wx\\Plugins\\bidemo;\n"
        . "use Wx\\Contracts\\PackageInterface;\nuse Wx\\Core\\Kernel;\n"
        . "final class BidemoPlugin implements PackageInterface {\n"
        . "    public function slug(): string { return 'bidemo'; }\n"
        . "    public function register(Kernel \$k): void {}\n}\n");
    file_put_contents($pkg . '/Payload.php', "<?php\n// pristine\n");
    // 台账预置一条带基线的记录；reconcile 对已有 key 且版本一致时只更新 version，保留 integrity。
    mkdir($dir . '/storage/lifecycle', 0775, true);
    file_put_contents($dir . '/storage/lifecycle/state.json', json_encode([
        'units' => ['plugin:bidemo' => [
            'type' => 'plugin', 'slug' => 'bidemo', 'version' => '1.0.0',
            'state' => 'active', 'path' => 'plugins/bidemo',
            'integrity' => Fs::baseline($pkg, ''),
        ]],
        'history' => [],
    ], JSON_UNESCAPED_UNICODE));
    // autoloader 绑定的是工作区根，隔离站点新增的类需显式载入（仅守卫环境需要）。
    require_once $pkg . '/BidemoPlugin.php';
    return [$dir, $pkg];
};

check('A1·G7 · 真实 Kernel::boot()：基线一致则装配，篡改则拒装载且不实例化类', function () use ($bootSite): void {
    [$dir, $pkg] = $bootSite();
    try {
        // 正例：基线一致
        $k1 = new \Wx\Core\Kernel($dir);
        $loaded1 = [];
        $k1->hooks()->on('plugin.loaded', static function (...$a) use (&$loaded1): void { $loaded1[] = $a[0] ?? ''; });
        $k1->boot();
        truthy(in_array('bidemo', $loaded1, true), '基线一致时包应被真实装配（门禁未误伤）');

        // 反例：落位后篡改载荷，用新内核模拟下一次请求
        file_put_contents($pkg . '/Payload.php', "<?php\n// injected\n");
        $k2 = new \Wx\Core\Kernel($dir);
        $loaded2 = [];
        $rejected = [];
        $k2->hooks()->on('plugin.loaded', static function (...$a) use (&$loaded2): void { $loaded2[] = $a[0] ?? ''; });
        $k2->hooks()->on('package.integrity.rejected', static function (...$a) use (&$rejected): void { $rejected[] = $a; });
        $k2->boot();
        truthy(!in_array('bidemo', $loaded2, true), '篡改包不得被实例化（F6 收口的核心）');
        truthy(count($rejected) >= 1 && ($rejected[0][0] ?? '') === 'plugin:bidemo',
            '篡改须经真实 boot 链路广播 rejected');
    } finally {
        Fs::removeDir($dir);
    }
});

check('A1·G8 · 架构落点静态锁：门禁在 LifecycleSystem 且 priority 20，core/ 零新增', function () use ($root): void {
    $sys = (string) file_get_contents($root . '/systems/Lifecycle/LifecycleSystem.php');
    truthy(str_contains($sys, 'new BootIntegrity'), '门禁须由装卸系统装配（不是内核）');
    truthy(str_contains($sys, '}, 20)'), '门禁须排在状态裁决（priority 10）之后');
    truthy(!is_file($root . '/core/BootIntegrity.php'), 'core/ 不得新增承载完整性逻辑');
    truthy(is_file($root . '/systems/Lifecycle/BootIntegrity.php'), '门禁须是系统层协作者');
});
