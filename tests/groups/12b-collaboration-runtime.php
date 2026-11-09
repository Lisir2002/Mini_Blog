<?php
declare(strict_types=1);

use Wx\Core\HookBus;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Systems\Content\MemoryContentRepository;
use Wx\Systems\Database\Db;
use Wx\Systems\Lifecycle\Fs;
use Wx\Systems\Lifecycle\LifecycleManager;
use Wx\Systems\Lifecycle\PackageVerifier;
use Wx\Systems\Lifecycle\StateMachine;

check('C5 装卸面：磁盘清单 / 状态 / 装载裁决点三方对账', function () use ($root): void {
    $k = kernel(null);
    $k->boot();
    /** @var \Wx\Contracts\LifecycleManagerInterface $m */
    $m = $k->container()->resolve(\Wx\Contracts\LifecycleManagerInterface::class);
    $units = $m->units();
    truthy(count($units) >= 11, '装卸单元数量异常：' . count($units));
    foreach ($units as $unit) {
        truthy(is_dir($root . '/' . $unit['path']), '状态登记的路径在磁盘不存在：' . $unit['unit']);
    }
    truthy($k->hooks()->has('package.enabled'), '装载裁决点未由装卸系统注册');
    $version = json_decode((string) file_get_contents($root . '/core/version.json'), true);
    $core = null;
    foreach ($units as $unit) {
        if ($unit['unit'] === 'core:core') $core = $unit;
    }
    truthy($core !== null && $core['version'] === ($version['version'] ?? null), 'core 版本未与磁盘清单对账');
});

check('C6 呈现面：主题绑定 / 渲染裁决 / standalone 独立页三者串联', function () use ($root): void {
    $headless = kernel(null);
    $headless->boot();
    truthy(!$headless->container()->has(\Wx\Contracts\RendererInterface::class), '无主题时不应绑定渲染器');

    $themed = pageKernel('bare');
    $themed->boot();
    truthy($themed->container()->has(\Wx\Contracts\RendererInterface::class), '有主题时未绑定渲染器');
    truthy($themed->hooks()->has('render.should_render'), '渲染裁决点缺失');

    $bareCss = trim((string) file_get_contents($root . '/themes/Bare/style.css'));
    $page = $themed->handle(new Request('GET', '/console', [], adminHeaders()));
    truthy(!str_contains($page->body(), $bareCss), 'standalone 独立页被站点主题样式覆盖');
    truthy(str_contains(strtolower($page->contentType()), 'text/html'), '独立页未输出自包含 HTML');
});

check('C7 数据面：连接状态跨系统对账，拔 DSN 优雅回退且数据链路不断', function () use ($root): void {
    $k = kernel(null);
    $k->boot();
    $info = json_decode($k->handle(new Request('GET', '/api/v1/system/info'))->body(), true);
    truthy(($info['data']['database'] ?? null) === (Db::connected($k) ? 'mysql' : 'fallback'),
        'system.info 与真实数据库连接状态不一致');

    $fallback = new Kernel($root, ['database' => ['dsn' => '']]);
    $fallback->boot();
    truthy($fallback->container()->resolve(\Wx\Contracts\ContentRepositoryInterface::class)
        instanceof MemoryContentRepository, '拔掉 DSN 未回退内存实现');
    $items = json_decode($fallback->handle(new Request('GET', '/'))->body(), true)['items'] ?? [];
    truthy(in_array('第一篇文章', $items, true), '回退后首页数据链路断裂');
});

check('C8 编排面：7 个协作者齐备，install 全链路协作轨迹覆盖全部角色', function () use ($root): void {
    $tmp = sys_get_temp_dir() . '/wx-collab-' . bin2hex(random_bytes(4));
    mkdir($tmp . '/plugins', 0775, true);
    mkdir($tmp . '/storage/packages', 0775, true);
    try {
        $bus = new HookBus();
        $roles = [];
        $bus->on('lifecycle.collab', static function (string $role) use (&$roles): void {
            $roles[$role] = true;
        });

        $m = new LifecycleManager($tmp, $bus, ['require_checksum' => true]);
        $collaborators = $m->collaborators();
        $expectedKeys = ['state_store', 'unit_catalog', 'dependency_graph',
            'package_verifier', 'file_transaction', 'hook_runner', 'state_machine'];
        foreach ($expectedKeys as $key) {
            truthy(isset($collaborators[$key]) && is_object($collaborators[$key]), '缺少协作者：' . $key);
        }

        $src = $tmp . '/storage/packages/demo-1.0';
        makeSourcePackage($src, '1.0.0', [], true);
        truthy($m->install('plugin', $src, ['slug' => 'demo'])['state'] === 'installed', '编排链路未完成安装');

        foreach (['catalog', 'state_machine', 'dependency_graph', 'package_verifier',
                  'file_transaction', 'hook_runner', 'state_store'] as $role) {
            truthy(isset($roles[$role]), '协作轨迹缺少角色：' . $role);
        }

        // 协作者可脱离编排者独立工作，证明职责确实被拆开而非换个地方堆着
        $machine = new StateMachine($bus);
        truthy($machine->can('absent', 'install') && !$machine->can('active', 'install'), '状态机独立裁决异常');
        $verifier = new PackageVerifier($tmp, $bus, ['require_checksum' => true]);
        truthy($verifier->verify($tmp . '/plugins/demo')['ok'] === true, '验签协作者独立校验失败');

        $threw = false;
        try {
            $m->uninstall('core', 'core');
        } catch (\Wx\Systems\Lifecycle\LifecycleException) {
            $threw = true;
        }
        truthy($threw, 'core 作为元单元应不可卸载');
    } finally {
        Fs::removeDir($tmp);
    }
});

check('C9 矩阵完整性：8 类协作面各有实际执行的守卫，审计零违规零孤儿', function () use ($root): void {
    $report = collabReport($root);
    truthy($report['violations'] === [], '职责预算仍有违规');
    truthy($report['collaboration']['orphans'] === [], '仍有孤儿协作');

    $names = $GLOBALS['wx_checks'];
    foreach ($report['collaboration']['matrix'] as $row) {
        $hit = false;
        foreach ($names as $name) {
            if (str_starts_with($name, $row['guard'] . ' ')) { $hit = true; break; }
        }
        truthy($hit, '协作面 ' . $row['id'] . ' 没有对应守卫：' . $row['name']);
    }

    $count = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') continue;
        $rel = ltrim(str_replace('\\', '/', str_replace($root, '', $file->getPathname())), '/');
        foreach (['.codebuddy/', 'storage/', 'docs/', 'vendor/'] as $skip) {
            if (str_starts_with($rel, $skip)) continue 2;
        }
        $count++;
    }
    truthy($report['summary']['php_files'] === $count, '审计扫描文件数与实际不一致');
});
