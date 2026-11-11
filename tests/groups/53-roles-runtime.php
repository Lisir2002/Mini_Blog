<?php
declare(strict_types=1);

use Wx\Core\Http\Request;
use Wx\Contracts\ApiRegistryInterface;

/**
 * 53 · 角色授权运行时：能看授权 ≠ 能改授权，改了要真生效
 *
 * 从 HTTP 面证明五件事：
 *  1. 匿名 401、非管理员 403、管理员拿到角色表与票据；
 *  2. 授权 / 撤销真的改的是同一份授权，重复提交说「本来就有」而不是假装成功；
 *  3. 页面挡住接口挡不住的两类脏数据：清单外的权限点、不存在的角色；
 *  4. 继承变更生效，成环被拒且关系不变；撤销继承来的许可要说清「来自继承」；
 *  5. 高危操作必须手写角色名，确认之后自锁真的会发生（证明确认不是装饰）。
 */
echo "\n=== 53. 角色授权运行时 ===\n";

$site   = tempSite('roles-runtime');
$prefix = 'rl_';
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
$field = static function (string $html, string $name): string {
    return preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html, $m)
        ? (string) $m[1] : '';
};
$registry = $k->container()->resolve(ApiRegistryInterface::class);

[, , $h] = $hit('POST', '/login', [], ['username' => 'admin', 'password' => 'admin123']);
$cookie = 'wx_session=' . cookieValue((string) ($h['Set-Cookie'] ?? ''), 'wx_session');

// 票据可重复提交（签名只绑权限点与路径，没有一次性 nonce）；一次取够。
[, $page] = $hit('GET', '/console/roles', [], [], ['Cookie' => $cookie]);
$ticket = $field($page, '_form_ticket');
truthy($ticket !== '', '管理员没拿到表单票据，后面所有写用例都会误判');

$post = static function (array $body) use ($hit, $cookie, $ticket): array {
    [$s, $b, $headers] = $hit('POST', '/console/roles', [],
        $body + ['_form_ticket' => $ticket], ['Cookie' => $cookie]);
    return [$s, (string) ($headers['Location'] ?? ''), $b];
};
$roles = static function () use ($registry): array {
    return (array) ($registry->execute('permission.roles', [])['roles'] ?? []);
};
$permsOf = static function (string $role) use ($roles): array {
    return array_map('strval', (array) ($roles()[$role]['permissions'] ?? []));
};

check('匿名访问：401，拒绝页指向真实登录入口', function () use ($hit): void {
    [$s, $b] = $hit('GET', '/console/roles');
    truthy($s === 401, '匿名应 401，实际 ' . $s);
    truthy(str_contains($b, '/login?next='), '拒绝页没有指向 /login 的真实入口');
});

check('管理员 GET：四个默认角色、直接与有效许可分列、票据已签发', function () use ($page): void {
    foreach (['admin', 'editor', 'author', 'subscriber'] as $role) {
        truthy(str_contains($page, '修改 ' . $role), '角色表里缺少 ' . $role);
    }
    truthy(str_contains($page, '直接许可'), '没有把直接许可单列');
    truthy(str_contains($page, '有效许可'), '没有把有效许可单列');
    truthy(str_contains($page, '继承自'), '没有把继承来源单列');
    truthy(str_contains($page, '/console/roles?catalog=1'), '没有权限点清单入口');
});

check('权限点清单可展开，且与系统层声明一致', function () use ($hit, $cookie, $registry): void {
    [$s, $b] = $hit('GET', '/console/roles', ['catalog' => '1'], [], ['Cookie' => $cookie]);
    truthy($s === 200, '展开清单应 200，实际 ' . $s);
    $total = count((array) ($registry->execute('permission.catalog', [])['permissions'] ?? []));
    truthy(str_contains($b, '可授权的权限点（' . $total . '）'),
        '清单行数与 permission.catalog 不一致（期望 ' . $total . '）');
});

check('授权：303，且真的写进了 editor 的直接许可', function () use ($post, $permsOf): void {
    [$s, $loc] = $post(['role' => 'editor', 'permission' => 'content.delete', 'action' => 'grant']);
    truthy($s === 303, '授权应 303，实际 ' . $s);
    truthy(str_contains($loc, 'saved=grant') && str_contains($loc, 'changed=1'),
        '303 目标没有带上结果标记：' . $loc);
    truthy(in_array('content.delete', $permsOf('editor'), true),
        '页面说已授予，editor 的直接许可里却没有 content.delete');
});

check('重复授权：303 但 changed=0，提示「本来就有」而不是假装成功', function () use ($post): void {
    [$s, $loc] = $post(['role' => 'editor', 'permission' => 'content.delete', 'action' => 'grant']);
    truthy($s === 303, '重复授权应 303，实际 ' . $s);
    truthy(str_contains($loc, 'changed=0'), '重复授权没有标出零变化：' . $loc);
});

check('撤销：303，直接许可里真的没有了', function () use ($post, $permsOf): void {
    [$s] = $post(['role' => 'editor', 'permission' => 'content.delete', 'action' => 'revoke']);
    truthy($s === 303, '撤销应 303，实际 ' . $s);
    truthy(!in_array('content.delete', $permsOf('editor'), true),
        '页面说已撤销，editor 的直接许可里仍有 content.delete');
});

check('清单外的权限点：页面 422，接口本身却会写进去', function () use ($post, $registry, $permsOf): void {
    [$s, , $b] = $post(['role' => 'editor', 'permission' => 'content.nope', 'action' => 'grant']);
    truthy($s === 422, '清单外权限点应 422，实际 ' . $s);
    truthy(str_contains($b, '不在清单里'), '没有说明为什么拒绝');
    truthy(!in_array('content.nope', $permsOf('editor'), true), '被拒绝的授权仍然写进了库');

    // 证据：接口层不校验清单，直接调用会写入一条永远不命中的许可。
    $registry->execute('permission.grant', ['role' => 'editor', 'permission' => 'content.nope']);
    truthy(in_array('content.nope', $permsOf('editor'), true),
        '接口没有写入，说明这条用例的前提已经变了（页面校验可以撤掉）');
    $registry->execute('permission.revoke', ['role' => 'editor', 'permission' => 'content.nope']);
});

check('不存在的角色：页面 422，不产生孤儿许可', function () use ($post, $roles): void {
    [$s, , $b] = $post(['role' => 'nosuch', 'permission' => 'content.edit', 'action' => 'grant']);
    truthy($s === 422, '不存在的角色应 422，实际 ' . $s);
    truthy(str_contains($b, '不存在'), '没有说明角色不存在');
    truthy(!isset($roles()['nosuch']), '被拒绝的授权凭空造出了角色');
});

check('继承：设父角色 303，祖先与有效许可立刻反映出来', function () use ($post, $roles): void {
    [$s, $loc] = $post(['role' => 'editor', 'parent' => 'author', 'action' => 'set_parent']);
    truthy($s === 303, '设继承应 303，实际 ' . $s);
    truthy(str_contains($loc, 'saved=set_parent'), '303 目标没有标出继承动作：' . $loc);
    $info = (array) ($roles()['editor'] ?? []);
    truthy(in_array('author', (array) ($info['ancestors'] ?? []), true), '祖先里没有 author');
    truthy(in_array('content.read', (array) ($info['effective_permissions'] ?? []), true),
        '有效许可里没有继承来的 content.read');
});

check('成环：409 且关系没被写进去', function () use ($post, $roles): void {
    [$s, , $b] = $post(['role' => 'author', 'parent' => 'editor', 'action' => 'set_parent']);
    truthy($s === 409, '成环应 409，实际 ' . $s);
    truthy(str_contains($b, '会形成环'), '没有把 cycle 翻成人话');
    $info = (array) ($roles()['author'] ?? []);
    truthy(!in_array('editor', (array) ($info['parents'] ?? []), true), '成环的关系被写进了库');
});

check('撤销继承来的许可：零变化，并说明「来自继承」', function () use ($post, $roles, $hit, $cookie): void {
    [$s, $loc] = $post(['role' => 'editor', 'permission' => 'content.read', 'action' => 'revoke']);
    truthy($s === 303, '撤销应 303，实际 ' . $s);
    truthy(str_contains($loc, 'changed=0'), '撤销继承来的许可不该报成功：' . $loc);

    $q = [];
    parse_str(str_contains($loc, '?') ? substr($loc, strpos($loc, '?') + 1) : '', $q);
    [, $b] = $hit('GET', '/console/roles', $q, [], ['Cookie' => $cookie]);
    truthy(str_contains($b, '来自继承'), '没有说明这条许可来自继承，管理员会以为撤销失败');
    truthy(in_array('content.read', (array) ($roles()['editor']['effective_permissions'] ?? []), true),
        '撤销直接许可后，继承来的 content.read 应仍然有效');
});

check('高危操作：不手写角色名一律 422，写对了才执行', function () use ($post, $permsOf): void {
    [$s, , $b] = $post(['role' => 'admin', 'permission' => '*', 'action' => 'revoke']);
    truthy($s === 422, '未确认的撤销应 422，实际 ' . $s);
    truthy(str_contains($b, '手写'), '没有说明需要手写确认');
    truthy(in_array('*', $permsOf('admin'), true), '被拒绝的撤销仍然生效了');
});

check('确认之后确实自锁：这条页面自己进不去了', function () use ($post, $hit, $cookie, $permsOf): void {
    [$s] = $post(['role' => 'admin', 'permission' => '*', 'action' => 'revoke', 'confirm_role' => 'admin']);
    truthy($s === 303, '带确认的撤销应 303，实际 ' . $s);
    truthy(!in_array('*', $permsOf('admin'), true), '确认之后通配符仍在，用例前提不成立');

    [$next] = $hit('GET', '/console/roles', [], [], ['Cookie' => $cookie]);
    truthy($next === 403, '撤销 admin 的 * 之后仍能进入，说明自锁没有发生（用例失效）');
});

check('授权页对普通账号关闭：新注册账号（subscriber）没有 permission.read', function () use ($hit): void {
    // 上一个用例已经把 admin 的通配符撤掉，这里改用一份全新站点上的 author 会话。
    $site = tempSite('roles-author');
    $prefix = 'ra_';
    dropTables($prefix);
    $k2 = isolatedKernel($site, $prefix, [
        'theme' => ['active' => 'glass'],
        'user'  => ['registration' => ['enabled' => true]],
        'permission' => ['cache' => ['driver' => 'memory']],
    ]);
    $k2->boot();
    $r = $k2->handle(new Request('POST', '/register', [], [],
        ['username' => 'writer1', 'password' => 'writer-pass-2026']));
    $c = 'wx_session=' . cookieValue((string) ($r->headers()['Set-Cookie'] ?? ''), 'wx_session');
    $g = $k2->handle(new Request('GET', '/console/roles', [], ['Cookie' => $c], []));
    truthy($g->status() === 403, 'subscriber 应 403，实际 ' . $g->status());
    dropTables($prefix);
});

dropTables($prefix);
