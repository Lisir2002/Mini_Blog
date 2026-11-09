<?php
declare(strict_types=1);

use Wx\Core\HookBus;
use Wx\Systems\Lifecycle\Fs;
use Wx\Systems\Lifecycle\LifecycleException;
use Wx\Systems\Lifecycle\LifecycleManager;

echo "\n=== 7. 装卸系统（元层）状态机 ===\n";

check('单元身份取自清单而非目录名', function () use ($root): void {
    $k = kernel(null);
    $k->boot();
    /** @var \Wx\Contracts\LifecycleManagerInterface $m */
    $m = $k->container()->resolve(\Wx\Contracts\LifecycleManagerInterface::class);
    $keys = array_column($m->units(), 'unit');
    truthy(in_array('plugin:hello', $keys, true), '未找到 plugin:hello，实际：' . implode(',', array_slice($keys, 0, 8)));
    truthy(!in_array('plugin:Hello', $keys, true), '仍在用目录名当单元身份');
    truthy($m->stateOf('plugin', 'hello') === 'active', '启用状态未按清单身份登记');
});

check('装卸全链路：安装→启用→升级→回滚→停用→校验→卸载', function (): void {
    $tmp = sys_get_temp_dir() . '/wx-lc-' . bin2hex(random_bytes(4));
    mkdir($tmp . '/plugins', 0775, true);
    mkdir($tmp . '/storage/packages', 0775, true);
    $manager = new LifecycleManager($tmp, new HookBus(), ['require_checksum' => true]);
    try {
        $v1 = $tmp . '/storage/packages/demo-1.0';
        makeSourcePackage($v1, '1.0.0', [], true);
        $r = $manager->install('plugin', $v1, ['slug' => 'demo']);
        truthy($r['state'] === 'installed' && $r['version'] === '1.0.0', '安装结果不正确');
        truthy(is_dir($tmp . '/plugins/demo'), '目标目录未创建');
        truthy(is_file($tmp . '/plugins/demo/installed.marker'), '原生 PHP 生命周期钩子 on_install 未执行');

        $threw = false;
        try { $manager->install('plugin', $v1, ['slug' => 'demo']); }
        catch (LifecycleException $e) { $threw = $e->apiCode === 40901; }
        truthy($threw, '重复安装应被状态机拒绝');

        truthy($manager->activate('plugin', 'demo')['state'] === 'active', '启用失败');
        truthy($manager->stateOf('plugin', 'demo') === 'active', '状态未持久化');

        $v2 = $tmp . '/storage/packages/demo-1.1';
        makeSourcePackage($v2, '1.1.0');
        $r = $manager->upgrade('plugin', 'demo', $v2);
        truthy($r['version'] === '1.1.0' && is_dir($r['backup']), '升级或备份失败');

        $threw = false;
        try { $manager->upgrade('plugin', 'demo', $v2); }
        catch (LifecycleException $e) { $threw = $e->apiCode === 40901; }
        truthy($threw, '版本未递增应被拒绝');

        $r = $manager->rollback('plugin', 'demo');
        truthy($r['version'] === '1.0.0' && $r['backup'] === null, '回滚失败');
        truthy($manager->deactivate('plugin', 'demo')['state'] === 'installed', '停用失败');

        $v = $manager->verify('plugin', 'demo');
        truthy($v['ok'] === true && $v['mode'] === 'sha256', '完整性校验失败');

        $r = $manager->uninstall('plugin', 'demo');
        truthy($r['removed'] === true && !is_dir($tmp . '/plugins/demo'), '卸载未删除目录');
    } finally {
        Fs::removeDir($tmp);
    }
});

check('装卸状态与磁盘清单版本自动对账', function (): void {
    $k = kernel(null);
    $k->boot();
    $m = $k->container()->resolve(\Wx\Contracts\LifecycleManagerInterface::class);
    $v = json_decode((string) file_get_contents($k->root() . '/core/version.json'), true);
    $core = null;
    foreach ($m->units() as $unit) {
        if ($unit['unit'] === 'core:core') $core = $unit;
    }
    truthy($core !== null && $core['version'] === ($v['version'] ?? null), 'core 单元版本未与 version.json 对账');
});

check('装卸安全：反向依赖 / 路径穿越 / 源包越界均被拒绝', function (): void {
    $tmp = sys_get_temp_dir() . '/wx-lc-' . bin2hex(random_bytes(4));
    mkdir($tmp . '/plugins', 0775, true);
    mkdir($tmp . '/storage/packages', 0775, true);
    $manager = new LifecycleManager($tmp, new HookBus());
    try {
        $a = $tmp . '/storage/packages/demo';
        makeSourcePackage($a, '1.0.0');
        $manager->install('plugin', $a, ['slug' => 'demo']);

        $b = $tmp . '/storage/packages/dependent';
        makeSourcePackage($b, '1.0.0', ['demo'], false, 'dependent');
        $manager->install('plugin', $b, ['slug' => 'dependent']);

        $threw = false;
        try { $manager->uninstall('plugin', 'demo'); }
        catch (LifecycleException $e) { $threw = $e->apiCode === 40901; }
        truthy($threw, '被依赖的单元不应允许卸载');

        $threw = false;
        try { $manager->install('plugin', $a, ['slug' => '../evil']); }
        catch (LifecycleException $e) { $threw = $e->apiCode === 42201; }
        truthy($threw, '路径穿越标识应被拒绝');

        $threw = false;
        try { $manager->install('plugin', '/etc', ['slug' => 'outside']); }
        catch (LifecycleException $e) { $threw = $e->apiCode === 40301; }
        truthy($threw, '根目录外的源包应被拒绝');
    } finally {
        Fs::removeDir($tmp);
    }
});
