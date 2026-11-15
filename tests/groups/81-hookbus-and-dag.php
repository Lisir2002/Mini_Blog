<?php
declare(strict_types=1);

use Wx\Core\HookBus;
use Wx\Core\Kernel;
use Wx\Core\PackageLoader;
use Wx\Systems\Lifecycle\DependencyGraph;
use Wx\Systems\Lifecycle\Fs;
use Wx\Systems\Lifecycle\LifecycleException;
use Wx\Systems\Lifecycle\LifecycleManager;
use Wx\Systems\Lifecycle\StateStore;
use Wx\Systems\Lifecycle\UnitCatalog;

echo "\n=== 81. A1 事件总线增强 / A3 依赖图 ===\n";

/** 隔离站点：只造装配所需的两级目录，不碰真实仓库。 */
function dagSite(): string
{
    $tmp = sys_get_temp_dir() . '/wx-dag-' . bin2hex(random_bytes(4));
    mkdir($tmp . '/plugins', 0775, true);
    mkdir($tmp . '/storage/packages', 0775, true);
    return $tmp;
}

/** 造可装配的承载包清单：复用出厂 HelloPlugin 类，避免为测试新增生产代码。 */
function dagPlugin(string $base, string $slug, array $requires = [], int $priority = 10): void
{
    $dir = $base . '/plugins/' . $slug;
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $meta = ['slug' => $slug, 'class' => 'Wx\\Plugins\\Hello\\HelloPlugin',
             'version' => '1.0.0', 'priority' => $priority, 'enabled' => true];
    if ($requires !== []) $meta['requires'] = $requires;
    file_put_contents($dir . '/plugin.json', json_encode($meta, JSON_UNESCAPED_SLASHES));
}

check('A1 句柄解绑与自省：on/filter 返回 id，off/clear 精确移除', function (): void {
    $bus = new HookBus();
    $log = [];
    $id = $bus->on('wx.a1', function () use (&$log): void { $log[] = 'a'; });
    $bus->on('wx.a1', function () use (&$log): void { $log[] = 'b'; }, 5);
    $fid = $bus->filter('wx.a1', fn($v) => $v . 'F');
    truthy(is_int($id) && is_int($fid) && $id !== $fid, '句柄应为不同整数');
    $ls = $bus->listeners('wx.a1');
    truthy(count($ls) === 3 && isset($ls[0]['once'], $ls[0]['priority'], $ls[0]['kind']), '自省字段不全');
    truthy($bus->off('wx.a1', $id) === true, 'off 未命中目标');
    truthy($bus->off('wx.a1', $id) === false, '重复 off 应如实返回 false');
    $bus->emit('wx.a1');
    truthy($log === ['b'], '解绑后仍执行了被移除的监听者');
    truthy($bus->apply('wx.a1', 'x') === 'xF', 'filter 不应被 off 误伤');
    truthy($bus->clear('wx.a1') === 2, 'clear 应移除剩余 action + filter');
    truthy($bus->listeners('wx.a1') === [] && !$bus->has('wx.a1'), 'clear 后仍有残留');
});

check('A1 once：一次性监听者触发后自动解绑', function (): void {
    $bus = new HookBus();
    $n = 0;
    $bus->once('wx.once', function () use (&$n): void { $n++; });
    truthy($bus->listeners('wx.once')[0]['once'] === true, 'once 标记未登记');
    $bus->emit('wx.once');
    $bus->emit('wx.once');
    truthy($n === 1, 'once 执行了 ' . $n . ' 次');
    truthy($bus->listeners('wx.once') === [], 'once 后监听者仍残留');
});

check('A1 异常隔离：坏监听者不截断后续链，失败留痕并经日志出口上报', function (): void {
    $bus = new HookBus();
    $log = [];
    $traces = [];
    $bus->on('kernel.trace', function (string $m, array $ctx) use (&$traces): void { $traces[] = $m; });
    $bus->on('wx.iso', function () use (&$log): void { $log[] = 'before'; });
    $bus->on('wx.iso', function (): void { throw new RuntimeException('bad plugin'); });
    $bus->on('wx.iso', function () use (&$log): void { $log[] = 'after'; });
    $bus->emit('wx.iso');
    truthy($log === ['before', 'after'], '异常截断了后续监听者');
    $err = $bus->errors();
    truthy(count($err) === 1 && $err[0]['tag'] === 'wx.iso'
        && str_contains($err[0]['message'], 'bad plugin'), '失败留痕不完整');
    truthy(in_array('钩子监听者失败已隔离', $traces, true), '失败未走日志出口');
});

check('A1 严格边界：控制流冒泡、strict 恢复旧语义、apply 永不隔离', function (): void {
    $bus = new HookBus();
    $bus->strictTag('wx.hard');
    $bus->on('wx.hard', function (): void { throw new RuntimeException('hard'); });
    $threw = false;
    try { $bus->emit('wx.hard'); } catch (RuntimeException $e) { $threw = $e->getMessage() === 'hard'; }
    truthy($threw, '严格控制流 tag 的异常应冒泡');

    $soft = new HookBus();
    $soft->on('wx.soft', function (): void { throw new RuntimeException('soft'); });
    $soft->emit('wx.soft');
    truthy(count($soft->errors()) === 1, '通知型默认应隔离');
    $soft->strict(true);
    $threw = false;
    try { $soft->emit('wx.soft'); } catch (RuntimeException) { $threw = true; }
    truthy($threw, 'strict(true) 应恢复旧语义');

    $f = new HookBus();
    $f->filter('wx.f', function (): void { throw new RuntimeException('filter'); });
    $threw = false;
    try { $f->apply('wx.f', 1); } catch (RuntimeException) { $threw = true; }
    truthy($threw && $f->errors() === [], 'apply 不应隔离也不应记入 errors');
});

check('A1 filter 契约：违约立即抛错，未声明 tag 零校验，合法链式改写不误伤', function (): void {
    $bus = new HookBus();
    $bus->expect('wx.bool', 'bool');
    $bus->filter('wx.bool', fn($v) => 'not-bool');
    $threw = false;
    try { $bus->apply('wx.bool', true); }
    catch (RuntimeException $e) { $threw = str_contains($e->getMessage(), '违反契约'); }
    truthy($threw, 'filter 返回值违约未拦下');
    truthy($bus->apply('wx.free', 1) === 1, '未声明 tag 不应被校验');

    $ok = new HookBus();
    $ok->expect('wx.num', 'int');
    $ok->filter('wx.num', fn(int $v): int => $v + 1);
    $ok->filter('wx.num', fn(int $v): int => $v * 2);
    truthy($ok->apply('wx.num', 3) === 8, '逐步类型校验误伤了合法链');
});

check('A3 单元目录：磁盘版本与台账状态一同暴露，身份仍取自清单', function (): void {
    $tmp = dagSite();
    $hooks = new HookBus();
    $state = new StateStore($tmp . '/storage/lifecycle', $hooks);
    $catalog = new UnitCatalog($tmp, $state, $hooks);
    try {
        dagPlugin($tmp, 'ghost');
        $rows = $catalog->allManifests();
        truthy($rows !== [] && isset($rows[0]['version'], $rows[0]['state']), '缺少 version/state 字段');
        truthy($rows[0]['identity'] === 'ghost' && $rows[0]['state'] === 'absent', '身份或状态不正确');
        $state->set('plugin:ghost', $catalog->record('plugin', 'ghost', '1.0.0', 'active'));
        truthy($catalog->allManifests()[0]['state'] === 'active', '台账状态未同步');
    } finally { Fs::removeDir($tmp); }
});

check('A3 版本约束：区间 / 版本不足 / 非法约束 / 反向依赖 @ 剥离', function (): void {
    $tmp = dagSite();
    $m = new LifecycleManager($tmp, new HookBus(), ['require_checksum' => true]);
    try {
        $dep = $tmp . '/storage/packages/dep';
        makeSourcePackage($dep, '1.2.0', [], false, 'dep');
        $m->install('plugin', $dep, ['slug' => 'dep']);

        $ok = $tmp . '/storage/packages/ok';
        makeSourcePackage($ok, '1.0.0', ['dep@>=1.0.0,<2.0.0'], false, 'ok');
        truthy($m->install('plugin', $ok, ['slug' => 'ok'])['state'] === 'installed', '合法区间被拒');

        $bad = $tmp . '/storage/packages/bad';
        makeSourcePackage($bad, '1.0.0', ['dep@>=2.0.0'], false, 'bad');
        $threw = false;
        try { $m->install('plugin', $bad, ['slug' => 'bad']); }
        catch (LifecycleException $e) { $threw = $e->apiCode === 40901 && str_contains($e->getMessage(), '版本'); }
        truthy($threw, '不满足的版本约束应被拒');

        $ill = $tmp . '/storage/packages/ill';
        makeSourcePackage($ill, '1.0.0', ['dep@wat'], false, 'ill');
        $threw = false;
        try { $m->install('plugin', $ill, ['slug' => 'ill']); }
        catch (LifecycleException $e) { $threw = $e->apiCode === 42201; }
        truthy($threw, '非法约束应显式拒绝而不是静默放行');

        $threw = false;
        try { $m->uninstall('plugin', 'dep'); }
        catch (LifecycleException $e) { $threw = $e->apiCode === 40901; }
        truthy($threw, '带版本约束的反向依赖未拦住卸载');
    } finally { Fs::removeDir($tmp); }
});

check('A3 状态约束与环检测：磁盘清单不等于已登记，回边即拒', function (): void {
    $tmp = dagSite();
    $hooks = new HookBus();
    $state = new StateStore($tmp . '/storage/lifecycle', $hooks);
    $catalog = new UnitCatalog($tmp, $state, $hooks);
    $graph = new DependencyGraph($tmp, $catalog, $hooks);
    try {
        dagPlugin($tmp, 'ghost');
        $threw = false;
        try { $graph->assertSatisfied(['requires' => ['ghost']], 'consumer'); }
        catch (LifecycleException $e) { $threw = $e->apiCode === 40901 && str_contains($e->getMessage(), '未登记'); }
        truthy($threw, '磁盘有清单但台账 absent 不应满足依赖');

        $state->set('plugin:ghost', $catalog->record('plugin', 'ghost', '1.0.0', 'active'));
        $graph->assertSatisfied(['requires' => ['ghost']], 'consumer');

        dagPlugin($tmp, 'ghost', ['consumer']);
        $threw = false;
        try { $graph->assertSatisfied(['requires' => ['ghost']], 'consumer'); }
        catch (LifecycleException $e) { $threw = $e->apiCode === 40901 && str_contains($e->getMessage(), '成环'); }
        truthy($threw, '依赖回边未被识别');
    } finally { Fs::removeDir($tmp); }
});

check('A3 拓扑装载序：依赖优先覆盖纯 priority，批内成环拒、图外依赖忽略', function (): void {
    $tmp = dagSite();
    $k = new Kernel($tmp);
    try {
        dagPlugin($tmp, 'alpha', [], 30);
        dagPlugin($tmp, 'beta', ['alpha'], 10);
        dagPlugin($tmp, 'gamma', ['beta@>=1.0.0'], 20);
        $order = array_keys((new PackageLoader($k, $tmp . '/plugins', 'plugin.json'))->load());
        truthy($order === ['alpha', 'beta', 'gamma'], '拓扑序错误：' . implode(',', $order));

        dagPlugin($tmp, 'delta', ['system:content']);
        $order2 = array_keys((new PackageLoader($k, $tmp . '/plugins', 'plugin.json'))->load());
        truthy(in_array('delta', $order2, true), '图外依赖不应导致装载失败');

        dagPlugin($tmp, 'alpha', ['gamma']);
        $threw = false;
        try { (new PackageLoader($k, $tmp . '/plugins', 'plugin.json'))->load(); }
        catch (RuntimeException $e) { $threw = str_contains($e->getMessage(), '成环'); }
        truthy($threw, '批内成环应显式抛错而非静默降级');
    } finally { Fs::removeDir($tmp); }
});

check('A1/A3 静态落点：core 仍 24 文件、关键方法齐备、单文件不超预算', function () use ($root): void {
    $core = count(glob($root . '/core/*.php') ?: []) + count(glob($root . '/core/*/*.php') ?: []);
    truthy($core === 24, 'core 文件数变化：' . $core);
    $hb = (string) file_get_contents($root . '/core/HookBus.php');
    foreach (['function off(', 'function once(', 'function clear(', 'function listeners(',
              'function errors(', 'function expect(', 'function strict(', 'function strictTag(',
              'catch (Throwable $e)'] as $n) {
        truthy(str_contains($hb, $n), 'HookBus 缺落点：' . $n);
    }
    $kn = (string) file_get_contents($root . '/core/Kernel.php');
    foreach (["expect('contract.override'", "expect('package.enabled'",
              "expect('render.should_render'", "expect('kernel.response.filter'",
              "strictTag('route.resolve')"] as $n) {
        truthy(str_contains($kn, $n), 'Kernel 缺声明：' . $n);
    }
    $pl = (string) file_get_contents($root . '/core/PackageLoader.php');
    truthy(str_contains($pl, 'topological(') && str_contains($pl, "explode('@'"), '拓扑落点缺失');
    $dg = (string) file_get_contents($root . '/systems/Lifecycle/DependencyGraph.php');
    foreach (['assertAcyclic(', 'function parse(', 'function satisfies(', "['state']"] as $n) {
        truthy(str_contains($dg, $n), '依赖图缺落点：' . $n);
    }
    truthy(str_contains((string) file_get_contents($root . '/systems/Lifecycle/UnitCatalog.php'), "'state'"),
        'allManifests 未返回状态');
    $m = \Wx\Tools\Audit\Policy::metrics($root . '/core/HookBus.php', 'core/HookBus.php');
    truthy($m['sloc'] <= 120, 'HookBus 超核心预算：' . $m['sloc']);
    $m2 = \Wx\Tools\Audit\Policy::metrics(
        $root . '/systems/Lifecycle/DependencyGraph.php', 'systems/Lifecycle/DependencyGraph.php');
    truthy($m2['sloc'] <= 190, 'DependencyGraph 超系统层预算：' . $m2['sloc']);
});
