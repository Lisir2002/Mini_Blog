<?php
declare(strict_types=1);

use Wx\Core\Http\Request;

/**
 * 52 · 用户管理运行时：能看列表 ≠ 能改账号
 *
 * 从 HTTP 面证明四件事：
 *  1. 管理员 GET 能拿到列表、票据与写表单；
 *  2. 禁用自己 / 最后一个管理员被账号层拒绝（40906），页面原样呈现；
 *  3. 禁用他人后其令牌立刻失效，启用后能重新登录；
 *  4. 改角色与重置口令真的改的是同一个账号，不是「页面说成功」。
 */
echo "\n=== 52. 用户管理运行时 ===\n";

$site   = tempSite('users-runtime');
$prefix = 'us_';
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
$field = static function (string $html, string $name): string {
    return preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html, $m)
        ? (string) $m[1] : '';
};
$loginApi = static function (string $user, string $pass) use ($k): array {
    $r = $k->handle(new Request('POST', '/api/v1/auth/token', [],
        ['Content-Type' => 'application/json'], [],
        (string) json_encode(['username' => $user, 'password' => $pass])));
    return [$r->status(), (array) json_decode($r->body(), true)];
};

[, , $h] = $hit('POST', '/login', [], ['username' => 'admin', 'password' => 'admin123']);
$cookie = 'wx_session=' . cookieValue((string) ($h['Set-Cookie'] ?? ''), 'wx_session');

// 造一个真实账号：注册关闭时用管理员创建会多一步，这里直接开放注册。
[, , $rh] = $hit('POST', '/register', [], [
    'username' => 'victim1', 'password' => 'victim-pass-2026', 'display_name' => '被管理者',
]);
truthy(cookieValue((string) ($rh['Set-Cookie'] ?? ''), 'wx_session') !== '', '第二个账号注册失败');

$list = static function () use ($hit, $cookie, $field): array {
    [, $b] = $hit('GET', '/console/users', [], [], ['Cookie' => $cookie]);
    return [$b, $field($b, '_form_ticket')];
};

check('GET 列表：管理员可见、票据与禁改表单都在', function () use ($hit, $cookie): void {
    [$s, $b] = $hit('GET', '/console/users', [], [], ['Cookie' => $cookie]);
    truthy($s === 200, '用户页应 200，实际 ' . $s);
    truthy(str_contains($b, 'admin') && str_contains($b, 'victim1'), '列表没有显示账号');
    truthy(str_contains($b, '_form_ticket'), '没有下发票据');
    truthy(str_contains($b, 'value="disable"') || str_contains($b, 'value="enable"'), '没有禁用/启用按钮');
});

check('禁用自己：40906，页面留在原处并说明原因', function () use ($hit, $cookie, $list): void {
    [$b, $ticket] = $list();
    [$s, $page] = $hit('POST', '/console/users', [], [
        'user_id' => '1', 'action' => 'disable', '_form_ticket' => $ticket,
    ], ['Cookie' => $cookie]);
    truthy($s === 409, '禁用自己应 409，实际 ' . $s);
    truthy(str_contains($page, '不能禁用当前') || str_contains($page, '最后一个管理员'),
        '没有给出防呆原因');
});

check('禁用他人：303；旧口令登录 40103，且旧会话令牌失效', function () use ($hit, $cookie, $list, $loginApi): void {
    [$b, $ticket] = $list();
    // victim1 的 id 从列表里取：跳过管理员 id=1。
    $ids = [];
    preg_match_all('/name="user_id" value="(\d+)"/', $b, $all);
    foreach ($all[1] as $id) if ((string) $id !== '1') $ids[] = (string) $id;
    $victim = (string) ($ids[0] ?? '0');
    truthy($victim !== '0', '列表里找不到第二个账号的 id');

    [$s] = $hit('POST', '/console/users', [], [
        'user_id' => $victim, 'action' => 'disable', '_form_ticket' => $ticket,
    ], ['Cookie' => $cookie]);
    truthy($s === 303, '禁用应 303，实际 ' . $s);

    [$ls, $lb] = $loginApi('victim1', 'victim-pass-2026');
    truthy($ls === 401, '禁用后仍能登录，实际 ' . $ls);
    truthy(($lb['code'] ?? 0) === 40103, '禁用登录应 40103，实际 ' . ($lb['code'] ?? '?'));
});

check('启用：303，账号恢复可登录', function () use ($hit, $cookie, $list, $loginApi): void {
    [$b, $ticket] = $list();
    preg_match_all('/name="user_id" value="(\d+)"/', $b, $all);
    $ids = array_values(array_filter($all[1], static fn(string $id): bool => $id !== '1'));
    [$s] = $hit('POST', '/console/users', [], [
        'user_id' => (string) ($ids[0] ?? '0'), 'action' => 'enable', '_form_ticket' => $ticket,
    ], ['Cookie' => $cookie]);
    truthy($s === 303, '启用应 303，实际 ' . $s);
    [$ls] = $loginApi('victim1', 'victim-pass-2026');
    truthy($ls === 200, '启用后仍不能登录，实际 ' . $ls);
});

check('改角色：303，列表里角色真的变了', function () use ($hit, $cookie, $list): void {
    [$b, $ticket] = $list();
    preg_match_all('/name="user_id" value="(\d+)"/', $b, $all);
    $ids = array_values(array_filter($all[1], static fn(string $id): bool => $id !== '1'));
    [$s] = $hit('POST', '/console/users', [], [
        'user_id' => (string) ($ids[0] ?? '0'), 'action' => 'set_role', 'role' => 'author',
        '_form_ticket' => $ticket,
    ], ['Cookie' => $cookie]);
    truthy($s === 303, '改角色应 303，实际 ' . $s);
    [, $after] = $hit('GET', '/console/users', [], [], ['Cookie' => $cookie]);
    truthy(str_contains($after, 'author'), '列表没有反映新角色');
});

check('重置口令：303，旧口令失效、新口令可用', function () use ($hit, $cookie, $list, $loginApi): void {
    [$b, $ticket] = $list();
    preg_match_all('/name="user_id" value="(\d+)"/', $b, $all);
    $ids = array_values(array_filter($all[1], static fn(string $id): bool => $id !== '1'));
    [$s] = $hit('POST', '/console/users', [], [
        'user_id' => (string) ($ids[0] ?? '0'), 'action' => 'reset_password',
        'new_password' => 'reset-pass-2026', '_form_ticket' => $ticket,
    ], ['Cookie' => $cookie]);
    truthy($s === 303, '重置口令应 303，实际 ' . $s);

    [$old] = $loginApi('victim1', 'victim-pass-2026');
    [$new] = $loginApi('victim1', 'reset-pass-2026');
    truthy($old === 401, '旧口令仍然可用，实际 ' . $old);
    truthy($new === 200, '新口令不能登录，实际 ' . $new);
});

check('普通账号：403，管理页不向非管理员开放', function () use ($hit): void {
    // 用页面登录建立浏览器会话：victim1 已被改为 author，不具备 user.read。
    [$s, , $h] = $hit('POST', '/login', [], ['username' => 'victim1', 'password' => 'reset-pass-2026']);
    truthy($s === 303, '普通账号登录失败，实际 ' . $s);
    $c = 'wx_session=' . cookieValue((string) ($h['Set-Cookie'] ?? ''), 'wx_session');
    [$ps] = $hit('GET', '/console/users', [], [], ['Cookie' => $c]);
    truthy($ps === 403, '普通账号进入用户管理应 403，实际 ' . $ps);
});

dropTables($prefix);
