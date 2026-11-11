<?php
declare(strict_types=1);

use Wx\Core\Http\Request;
use Wx\Contracts\ApiRegistryInterface;

/**
 * 54 · 决策轨迹运行时：解释「这一次请求为什么被允许」
 *
 * 从 HTTP 面证明五件事：
 *  1. 匿名 401；有 permission.read 但没有 audit.read 的编辑 403（审计信息更敏感）；
 *  2. 决策链里能看到本次请求的 route.access 判定，证明它是「本进程快照」；
 *  3. 现场裁决三态分得清：允许 / 拒绝 / 不表态；
 *  4. 空动作不是 500，而是「没有动作可裁决」；
 *  5. 页面明说自己不是历史，并把历史位置指出来。
 */
echo "\n=== 54. 决策轨迹运行时 ===\n";

$site   = tempSite('decisions-runtime');
$prefix = 'dc_';
dropTables($prefix);
$k = isolatedKernel($site, $prefix, [
    'theme' => ['active' => 'glass'],
    'user'  => ['registration' => ['enabled' => true]],
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

check('匿名访问：401，拒绝页指向 /login', function () use ($hit): void {
    [$s, $b] = $hit('GET', '/console/decisions');
    truthy($s === 401, '匿名应 401，实际 ' . $s);
    truthy(str_contains($b, '/login?next='), '拒绝页没有指向真实登录入口');
});

[, , $h] = $hit('POST', '/login', [], ['username' => 'admin', 'password' => 'admin123']);
$cookie = 'wx_session=' . cookieValue((string) ($h['Set-Cookie'] ?? ''), 'wx_session');

// 造一个编辑账号：注册默认 subscriber，再用接口改成 editor（有 permission.read，没有 audit.read）。
[, , $rh] = $hit('POST', '/register', [], ['username' => 'ed1', 'password' => 'ed-pass-2026']);
$edCookie = 'wx_session=' . cookieValue((string) ($rh['Set-Cookie'] ?? ''), 'wx_session');
$edId = 0;
foreach ((array) ($registry->execute('users.list', ['limit' => 20, 'offset' => 0, 'status' => ''])['items'] ?? []) as $u) {
    if (is_array($u) && (string) ($u['username'] ?? '') === 'ed1') $edId = (int) ($u['id'] ?? 0);
}
truthy($edId > 0, '没造出测试用的编辑账号');
$registry->execute('users.manage', [
    'user_id' => $edId, 'action' => 'set_role', 'role' => 'editor',
    '_identity' => ['user_id' => 1, 'username' => 'admin', 'role' => 'admin'],
]);
// 改角色不会撤销令牌，但会话里的角色快照可能还是 subscriber：重新登录一次。
[, , $rh] = $hit('POST', '/login', [], ['username' => 'ed1', 'password' => 'ed-pass-2026']);
$edCookie = 'wx_session=' . cookieValue((string) ($rh['Set-Cookie'] ?? ''), 'wx_session');

check('非管理员进不了后台：页面级 auth=admin 比权限点更严', function () use ($hit, $edCookie): void {
    // editor 的默认许可里有 permission.read / user.read，但 page.json 声明 auth=admin。
    // 也就是说后台目前只对 admin 角色开放，权限点是在 admin 内部做二次裁剪。
    // 这个用例记录现状，不是期望：页面级 auth 与角色默认许可之间的错配见 docs/DECISIONS.md。
    [$s] = $hit('GET', '/console/roles', [], [], ['Cookie' => $edCookie]);
    truthy($s === 403, 'editor 目前应被 auth=admin 挡住，实际 ' . $s);
    [$s2] = $hit('GET', '/console/decisions', [], [], ['Cookie' => $edCookie]);
    truthy($s2 === 403, 'editor 应 403，实际 ' . $s2);
});

check('决策链是本次请求的：能看到 route.access 对 audit.read 的判定', function () use ($hit, $cookie): void {
    [$s, $b] = $hit('GET', '/console/decisions', [], [], ['Cookie' => $cookie]);
    truthy($s === 200, '管理员应 200，实际 ' . $s);
    truthy(str_contains($b, '本次请求的决策链'), '没有决策链区块');
    truthy(str_contains($b, 'permission.audit.read'),
        '决策链里没有本次请求的 route.access 判定，说明它不是本进程快照');
});

check('允许：admin 有通配符，permission.read 判为允许', function () use ($hit, $cookie): void {
    [$s, $b] = $hit('GET', '/console/decisions', ['action' => 'permission.read'], [], ['Cookie' => $cookie]);
    truthy($s === 200, '现场裁决应 200，实际 ' . $s);
    truthy(str_contains($b, '裁决结果：允许'), '没有判为允许');
});

check('不表态：清单里没有的动作，文案指向「名字写错」', function () use ($hit, $cookie): void {
    [$s, $b] = $hit('GET', '/console/decisions', ['action' => 'content.nope'], [], ['Cookie' => $cookie]);
    truthy($s === 200, '应 200，实际 ' . $s);
    truthy(str_contains($b, '裁决结果：不表态'), '未知动作被判成了允许或拒绝');
    truthy(str_contains($b, '不在权限清单里'), '没有解释「不表态」的常见原因');
});

check('拒绝与允许必须能区分：资源 ACL 显式 deny 压过通配符', function () use ($registry, $hit, $cookie): void {
    // 给 post:99 加一条 deny：subject=all，动作 content.read。
    $registry->execute('permission.acl.set', [
        'type' => 'post', 'id' => '99', 'subject' => '*',
        'capability' => 'content.read', 'allow' => false,
    ]);
    [$s, $b] = $hit('GET', '/console/decisions',
        ['action' => 'content.read', 'type' => 'post', 'id' => '99'], [], ['Cookie' => $cookie]);
    truthy($s === 200, '应 200，实际 ' . $s);
    truthy(str_contains($b, '裁决结果：拒绝'), '显式 deny 没有被判为拒绝');

    [, $ok] = $hit('GET', '/console/decisions', ['action' => 'content.read'], [], ['Cookie' => $cookie]);
    truthy(str_contains($ok, '裁决结果：允许'), '没有资源时不带 deny，应允许');
});

check('空动作不是 500：说明「没有动作可裁决」', function () use ($hit, $cookie): void {
    [$s, $b] = $hit('GET', '/console/decisions', ['action' => ''], [], ['Cookie' => $cookie]);
    truthy($s === 200, '空动作应 200，实际 ' . $s);
    truthy(str_contains($b, '没有动作可裁决'), '空动作没有给出可读原因');
});

check('超长动作：提示「结果没有意义」，仍然不 500', function () use ($hit, $cookie): void {
    [$s, $b] = $hit('GET', '/console/decisions', ['action' => str_repeat('a', 130)], [], ['Cookie' => $cookie]);
    truthy($s === 200, '超长动作应 200，实际 ' . $s);
    truthy(str_contains($b, '超过 120 字节'), '没有提示动作名过长');
});

check('边界写在页面上：这不是历史，历史在 permission_audit', function () use ($hit, $cookie): void {
    [, $b] = $hit('GET', '/console/decisions', [], [], ['Cookie' => $cookie]);
    truthy(str_contains($b, '这不是历史'), '没有说明快照性质');
    truthy(str_contains($b, 'permission_audit'), '没有指出历史留痕的位置');
    truthy(str_contains($b, '最近 100 条'), '没有说明容量上限');
});

dropTables($prefix);
