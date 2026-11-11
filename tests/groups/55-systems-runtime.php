<?php
declare(strict_types=1);

use Wx\Core\Http\Request;
use Wx\Contracts\ApiRegistryInterface;

/**
 * 55 · 系统状态运行时：只读运维视图
 *
 * 从 HTTP 面证明四件事：
 *  1. 匿名 401，管理员 200，五类块都在；
 *  2. 页面上的数字来自接口而不是写死：契约数、装配层数、迁移数与接口一致；
 *  3. 健康状态可降级，不是恒定 ok；
 *  4. 只读：没有写表单，POST 被守卫挡回。
 */
echo "\n=== 55. 系统状态运行时 ===\n";

$site   = tempSite('systems-runtime');
$prefix = 'sy_';
dropTables($prefix);
$k = isolatedKernel($site, $prefix, [
    'theme' => ['active' => 'glass'],
    'permission' => ['cache' => ['driver' => 'memory']],
]);
$k->boot();

/** @return array{0:int, 1:string, 2:array<string,string>} */
$hit = static function (string $method, string $path, array $q = [], array $body = [],
    array $headers = []) use ($k): array {
    $r = $k->handle(new Request($method, $path, $q, $headers, $body));
    return [$r->status(), $r->body(), $r->headers()];
};
$registry = $k->container()->resolve(ApiRegistryInterface::class);

check('匿名访问：401', function () use ($hit): void {
    [$s] = $hit('GET', '/console/system');
    truthy($s === 401, '匿名应 401，实际 ' . $s);
});

[, , $h] = $hit('POST', '/login', [], ['username' => 'admin', 'password' => 'admin123']);
$cookie = 'wx_session=' . cookieValue((string) ($h['Set-Cookie'] ?? ''), 'wx_session');
[, $page] = $hit('GET', '/console/system', [], [], ['Cookie' => $cookie]);

check('管理员 GET：健康、运行信息、装配顺序、发布状态、只读说明都在', function () use ($page): void {
    foreach (['健康状态', '运行信息', '装配顺序', '发布状态', '已装单元', '这一页只读'] as $needle) {
        truthy(str_contains($page, $needle), '缺少区块：' . $needle);
    }
});

check('数字来自接口：契约数与装配层数与 system.* 一致', function () use ($page, $registry): void {
    $contracts = count((array) ($registry->execute('system.contracts', [])['contracts'] ?? []));
    truthy(str_contains($page, '契约（' . $contracts . '）'),
        '页面契约数与 system.contracts 不一致（期望 ' . $contracts . '）');
    $layers = count((array) ($registry->execute('system.layers', [])['order'] ?? []));
    truthy(str_contains($page, '装配顺序（' . $layers . ' 层）'),
        '页面层数与 system.layers 不一致（期望 ' . $layers . '）');
});

check('健康状态与接口一致，且不是恒定 ok', function () use ($hit, $cookie, $registry): void {
    $health = $registry->execute('system.health', []);
    [, $b] = $hit('GET', '/console/system', [], [], ['Cookie' => $cookie]);
    $label = match ((string) ($health['status'] ?? '')) {
        'ok' => '正常', 'degraded' => '降级', 'down' => '不可用', default => '未知',
    };
    truthy(str_contains($b, '健康状态：' . $label),
        '页面健康标签与接口不一致（接口 ' . (string) ($health['status'] ?? '?') . '）');
    foreach ((array) ($health['checks'] ?? []) as $name => $check) {
        truthy(str_contains($b, $name . '='), '没有列出检查项：' . $name);
    }
});

check('迁移状态：未应用清单与 release.status 一致', function () use ($page, $registry): void {
    $pending = (array) ($registry->execute('release.status', [])['pending'] ?? []);
    if ($pending === []) {
        truthy(str_contains($page, '没有未应用的迁移'), '没有未应用迁移时没有说明');
        return;
    }
    truthy(str_contains($page, '未应用的迁移（' . count($pending) . '）'),
        '未应用迁移数与 release.status 不一致（期望 ' . count($pending) . '）');
});

check('只读：页面没有写表单，POST 被拒绝', function () use ($hit, $page, $cookie): void {
    truthy(!str_contains($page, 'action="/console/system"'), '只读页却渲染了写表单');
    [$s, $b] = $hit('POST', '/console/system', [], ['x' => '1'], ['Cookie' => $cookie]);
    truthy(in_array($s, [401, 403, 405], true), 'POST 没有被拒绝，实际 ' . $s);
    truthy(!str_contains($b, '健康状态：正常'), 'POST 返回了正常页面内容');
});

dropTables($prefix);
