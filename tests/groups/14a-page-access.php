<?php
declare(strict_types=1);

use Wx\Contracts\AccessControlInterface;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Systems\Page\PagePolicy;

echo "\n=== 14a. 页面身份权限：清单分级 / 短路 / 统一决策 ===\n";

check('清单分级：6 个页面都显式声明 access，未声明的策略缺省 fail-closed', function () use ($root): void {
    $decls = [];
    foreach (glob($root . '/pages/*/page.json') ?: [] as $f) {
        $d = json_decode((string) file_get_contents($f), true);
        $decls[(string) $d['slug']] = $d['access'] ?? null;
    }
    truthy(count($decls) === 6, '页面清单数量异常：' . count($decls));
    foreach ($decls as $slug => $acc) {
        truthy(is_array($acc) && isset($acc['auth']), $slug . ' 未显式声明 access');
    }
    truthy($decls['home']['auth'] === 'public' && $decls['links']['auth'] === 'public', '公开页分级不对');
    truthy($decls['console']['auth'] === 'user'
        && $decls['console']['permission'] === 'page.console.read', '控制台分级不对');
    truthy($decls['toolbox']['auth'] === 'admin', '工具页分级不对');
    // v0.11：全新站点没有可登录用户，安装页必须 public；安全靠「仅本机 + 一次性令牌 + 安装锁」。
    truthy($decls['install']['auth'] === 'public' && $decls['install']['local_only'] === true,
        '安装窗口的分级不对（应为 public + local_only）');

    $policy = new PagePolicy(new Kernel($root));
    $policy->add('probe', ['routes' => ['/probe']]);
    $rule = $policy->forPath('/probe');
    truthy(($rule['auth'] ?? null) === 'user', '未声明 access 的页面应缺省 fail-closed');
    truthy($policy->undeclared() === ['probe'], '未声明页面未进入告警清单');
    truthy($policy->forPath('/api/v1/system/info') === null, '未声明路径不应被页面系统裁决');
});

check('公开页匿名可访问；API 与未登记路径不受页面裁决影响', function (): void {
    $k = kernel(null);
    foreach (['/', '/links'] as $path) {
        $r = $k->handle(new Request('GET', $path));
        truthy($r->status() === 200, $path . ' 公开页被拦下');
        truthy(array_key_exists('identity', (array) json_decode($r->body(), true)), $path . ' 未注入身份字段');
    }
    truthy($k->handle(new Request('GET', '/api/v1/system/info'))->status() === 200, 'API 被页面裁决误拦');
    truthy($k->handle(new Request('GET', '/no-such-page'))->status() === 200, '未登记路径不应被拒绝');
});

check('受保护页匿名 401：短路使页面业务逻辑根本未执行', function (): void {
    $k = adminKernel();
    foreach (['/console', '/toolbox'] as $path) {
        $r = $k->handle(new Request('GET', $path));
        truthy($r->status() === 401, $path . ' 匿名未返回 401，实际 ' . $r->status());
        $d = (array) json_decode($r->body(), true);
        truthy(($d['reason'] ?? null) === 'unauthenticated', $path . ' 拒绝原因不对');
        truthy(($d['login_endpoint'] ?? null) === '/api/v1/auth/token', $path . ' 未给出登录入口');
        truthy(($d['return_to'] ?? null) === $path, $path . ' 未给出回跳路径');
        truthy(!str_contains($r->body(), '站点控制台'), $path . ' 页面业务数据被执行（未短路）');
        truthy(!str_contains(strtolower($r->body()), '<html'), $path . ' 无主题时出现 HTML');
    }
});

check('级别 + 权限双重裁决：editor 可进控制台不可进工具页，author 连控制台都进不去', function (): void {
    $editor = roleKernel('editor');
    truthy($editor->handle(new Request('GET', '/console', [], roleHeaders('editor')))->status() === 200,
        'editor 应可访问控制台（默认授予 page.console.read）');
    $no = $editor->handle(new Request('GET', '/toolbox', [], roleHeaders('editor')));
    truthy($no->status() === 403, 'editor 不应访问工具页');
    truthy((json_decode($no->body(), true)['reason'] ?? null) === 'level_denied', '工具页应因级别不足被拒');

    $author = roleKernel('author');
    $no2 = $author->handle(new Request('GET', '/console', [], roleHeaders('author')));
    truthy($no2->status() === 403, 'author 不应访问控制台');
    truthy((json_decode($no2->body(), true)['reason'] ?? null) === 'permission_denied', '应因权限点缺失被拒');
});

check('local_only：非本机来源访问安装向导被拒，本机来源放行', function (): void {
    // 用隔离站点：安装页会创建 storage/install.token，不能写进工作区。
    $dir = tempSite('page-install');
    dropTables('rt14a_');
    try {
        $k = isolatedKernel($dir, 'rt14a_');
        $r = $k->handle(new Request('GET', '/install', [], [], [], '', '203.0.113.9'));
        truthy($r->status() === 403, '公网来源访问安装页未被拒');
        truthy((json_decode($r->body(), true)['reason'] ?? null) === 'local_only', '拒绝原因应为 local_only');
        truthy($k->handle(new Request('GET', '/install'))->status() === 200, '本机访问安装页应放行');
    } finally {
        dropTables('rt14a_');
        \Wx\Systems\Lifecycle\Fs::removeDir($dir);
    }
});

check('fail-closed：撤销 admin 通配许可后后台被拒，恢复后放行', function (): void {
    $k = adminKernel();
    $k->boot();
    $acl = $k->container()->resolve(AccessControlInterface::class);
    try {
        $acl->revoke('admin', '*');
        $r = $k->handle(new Request('GET', '/console', [], adminHeaders()));
        truthy($r->status() === 403, '撤销许可后后台未被拒');
        truthy((json_decode($r->body(), true)['reason'] ?? null) === 'permission_denied', '拒绝原因不对');
    } finally {
        $acl->grant('admin', '*');
    }
    truthy($k->handle(new Request('GET', '/console', [], adminHeaders()))->status() === 200, '恢复许可后未放行');
});
