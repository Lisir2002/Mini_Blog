<?php
declare(strict_types=1);

use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Systems\Page\PagePolicy;

echo "\n=== 14b. 页面策略可编程但不可提权 / 拒绝页归主题 / 留痕 ===\n";

check('page.access.policy 只能收紧：提升级别生效，放宽为 public 被忽略', function () use ($root): void {
    $k = kernel(null);
    $k->boot();
    $k->hooks()->filter('page.access.policy', static function (array $rule, string $path): array {
        if ($path === '/')        $rule['auth'] = 'admin';
        if ($path === '/console') { $rule['auth'] = 'public'; $rule['permission'] = ''; }
        return $rule;
    }, 10);

    // 静态核对：放宽项一律不采纳，主权限点不允许被钩子清空
    $policy = new PagePolicy($k);
    $policy->add('console', ['routes' => ['/console'],
        'access' => ['auth' => 'user', 'permission' => 'page.console.read']]);
    $rule = $policy->forPath('/console');
    truthy(($rule['auth'] ?? null) === 'user', '放宽 auth 未被忽略');
    truthy(($rule['permission'] ?? null) === 'page.console.read', '清空 permission 未被忽略');

    $home = $k->handle(new Request('GET', '/'));
    truthy($home->status() === 401, '收紧策略未生效：公开页应被提升为 admin');
    $console = $k->handle(new Request('GET', '/console'));
    truthy($console->status() === 401, '放宽策略不应被采纳，控制台仍应拒绝匿名');
});

check('extra_permission 追加：公开页挂上额外权限点后匿名被拒，带权限身份放行', function (): void {
    $k = adminKernel();
    $k->boot();
    $k->hooks()->filter('page.access.policy', static function (array $rule, string $path): array {
        if ($path === '/links') $rule['extra_permission'] = 'page.install.run';
        return $rule;
    }, 10);
    $r = $k->handle(new Request('GET', '/links'));
    truthy($r->status() === 403, '追加 extra_permission 后匿名未被拒');
    truthy((json_decode($r->body(), true)['reason'] ?? null) === 'permission_denied', '拒绝原因不对');
    truthy($k->handle(new Request('GET', '/links', [], adminHeaders()))->status() === 200,
        '具备该权限的身份应放行');
});

check('规矩 J：拒绝页 HTML 来自主题 deny 布局，主题缺 deny 则退化为纯 JSON', function () use ($root): void {
    $r = pageKernel('bare')->handle(new Request('GET', '/console'));
    truthy($r->status() === 401, '匿名后台应被拒');
    truthy(str_contains($r->contentType(), 'text/html'), 'bare 主题应提供 deny 布局');
    $denyCss = trim((string) file_get_contents($root . '/themes/Bare/assets/deny.css'));
    $adminCss = trim((string) file_get_contents($root . '/themes/Bare/assets/admin.css'));
    truthy(str_contains($r->body(), $denyCss), '拒绝页样式并非来自主题 deny 布局');
    truthy(str_contains($r->body(), 'data-layout="deny"'), '拒绝页未使用 deny 槽位');
    truthy(!str_contains($r->body(), $adminCss), '拒绝页误用了 admin 布局样式');

    $r2 = pageKernel('bare-html')->handle(new Request('GET', '/console'));
    truthy(str_contains($r2->contentType(), 'json'), '主题缺 deny 布局时未退化为纯数据');
    truthy(!str_contains(strtolower($r2->body()), '<html'), '主题缺 deny 布局时出现兜底 HTML');

    truthy((glob($root . '/pages/*/templates/*.php') ?: []) === [], '独立页仍持有模板');
    truthy((glob($root . '/pages/*/assets/*') ?: []) === [], '独立页仍持有样式资源');
});

check('拒绝响应只产数据 + blocks 协议，不含任何标签与样式', function (): void {
    $r = kernel(null)->handle(new Request('GET', '/console'));
    $d = (array) json_decode($r->body(), true);
    truthy(($d['page'] ?? null) === 'access_denied', '拒绝数据结构不对');
    truthy(($d['status'] ?? null) === 401 && ($d['auth_level'] ?? null) === 'user', '拒绝元数据不全');
    truthy(count((array) ($d['blocks'] ?? [])) >= 2, '拒绝页未使用通用 blocks 协议');
    truthy(!isset($d['html']) && !isset($d['css']), '拒绝数据夹带了呈现内容');
});

check('每次页面访问裁决都留痕，交由内核可观测性出口', function (): void {
    $k = adminKernel();
    $seen = [];
    $k->hooks()->on('kernel.trace', static function (string $msg, array $ctx) use (&$seen): void {
        if ($msg === '页面访问裁决') $seen[] = $ctx;
    }, 10);
    $k->handle(new Request('GET', '/console', [], adminHeaders()));
    truthy(count($seen) === 1, '页面访问裁决未留痕或重复留痕');
    truthy(($seen[0]['allowed'] ?? null) === true, '留痕结果不对');
    truthy(($seen[0]['actor'] ?? null) === 'admin', '留痕未记录主体');
    truthy(($seen[0]['permission'] ?? null) === 'page.console.read', '留痕未记录权限点');
});

check('身份贯通：公开页带令牌注入同一身份，API 响应不被页面数据注入污染', function (): void {
    $k = adminKernel();
    $home = json_decode($k->handle(new Request('GET', '/', [], adminHeaders()))->body(), true);
    truthy(($home['identity']['username'] ?? null) === 'admin', '公开页未注入可选身份');
    $api = json_decode($k->handle(new Request('GET', '/api/v1/system/info', [], adminHeaders()))->body(), true);
    truthy(!array_key_exists('identity', (array) $api), 'API 信封不应被页面身份注入污染');
    truthy(!array_key_exists('identity', (array) ($api['data'] ?? [])), 'API data 不应被页面身份注入污染');
});
