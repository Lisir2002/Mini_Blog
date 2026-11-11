<?php
declare(strict_types=1);

use Wx\Core\Http\Request;
use Wx\Pages\Auth\AuthBlocks;

/**
 * 50 · 登录 / 注册入口：门必须先存在，再说谁能进
 *
 * 这一组回答四件事：
 *  1. 匿名 GET /login 真的是一张可提交的表单，不是「需要登录」的循环；
 *  2. 口令错与账号锁定各有自己的文案，且失败响应不写会话 Cookie；
 *  3. 登录成功是 303 + HttpOnly Cookie，刷新不会重复提交；
 *  4. /account 的写表单必须带票据，光有 Cookie 提交一律 401。
 */
echo "\n=== 50. 登录 / 注册入口 ===\n";

check('区块四态可区分：失败保留用户名、注册关闭不渲染表单', function (): void {
    $failed = AuthBlocks::login(['username' => 'alice'], ['message' => '用户名或口令不正确'], '', '/account');
    truthy(($failed[0]['state'] ?? '') === 'failed', '失败态没有独立标记');
    truthy(str_contains((string) ($failed[0]['value'] ?? ''), '口令'), '失败文案没有说明原因');
    $field = (array) (($failed[1]['fields'] ?? [])[0] ?? []);
    truthy(($field['value'] ?? '') === 'alice', '失败后没有保留已填用户名');

    $closed = AuthBlocks::register([], null, true);
    truthy(($closed[0]['state'] ?? '') === 'closed', '注册关闭没有独立状态');
    truthy(!isset($closed[1]['action']), '注册关闭时仍渲染了可提交表单');
});

$site   = tempSite('auth-pages');
$prefix = 'ap_';
dropTables($prefix);
$k = isolatedKernel($site, $prefix, [
    'theme' => ['active' => 'glass-front'],
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

check('匿名 GET /login：200 表单，无票据（凭据本身就是证明）', function () use ($hit): void {
    [$s, $b, $h] = $hit('GET', '/login');
    truthy($s === 200, '登录页应 200，实际 ' . $s);
    truthy(str_contains($b, 'action="/login"'), '没有指向 /login 的表单');
    truthy(str_contains($b, 'type="password"'), '口令输入框不是 password 类型');
    truthy(!str_contains($b, '_form_ticket'), '公开登录表单不该带票据');
    truthy(!isset($h['Set-Cookie']), 'GET 登录页不该下发凭据');
});

check('口令错误：401 + 说明 + 保留用户名，且不写 Cookie', function () use ($hit): void {
    [$s, $b, $h] = $hit('POST', '/login', [], [
        'username' => 'admin', 'password' => 'definitely-wrong',
    ]);
    truthy($s === 401, '口令错应 401，实际 ' . $s);
    truthy(str_contains($b, '用户名或口令不正确'), '没有给出可读的失败原因');
    truthy(str_contains($b, 'value="admin"'), '失败后没有保留用户名');
    truthy(!isset($h['Set-Cookie']), '失败响应写入了会话 Cookie');
});

check('登录成功：303 回账号页 + HttpOnly / SameSite 会话 Cookie', function () use ($hit): void {
    [$s, $b, $h] = $hit('POST', '/login', [], ['username' => 'admin', 'password' => 'admin123']);
    truthy($s === 303, '登录成功应 303，实际 ' . $s);
    truthy(($h['Location'] ?? '') === '/account', '没有回到账号页：' . ($h['Location'] ?? '-'));
    $cookie = (string) ($h['Set-Cookie'] ?? '');
    truthy(str_contains($cookie, 'wx_session='), '没有写 wx_session');
    truthy(stripos($cookie, 'HttpOnly') !== false, 'Cookie 缺少 HttpOnly');
    truthy(stripos($cookie, 'SameSite=Lax') !== false, 'Cookie 缺少 SameSite');
    truthy($b === '', '303 不该带正文');
    truthy((string) ($h['Location'] ?? '') !== '/login', '登录成功又回到登录页（循环）');
});

check('已登录再访问 /login：303 直接进账号页，不再显示表单', function () use ($hit): void {
    [, , $h] = $hit('POST', '/login', [], ['username' => 'admin', 'password' => 'admin123']);
    $cookie = 'wx_session=' . cookieValue((string) ($h['Set-Cookie'] ?? ''), 'wx_session');
    [$s, , $h2] = $hit('GET', '/login', [], [], ['Cookie' => $cookie]);
    truthy($s === 303, '已登录访问登录页应 303，实际 ' . $s);
    truthy(($h2['Location'] ?? '') === '/account', '没有跳去账号页');
});

check('匿名 GET /account：401 并指向真实登录入口，而不是空表单', function () use ($hit): void {
    [$s, $b] = $hit('GET', '/account');
    truthy($s === 401, '匿名访问账号页应 401，实际 ' . $s);
    truthy(str_contains($b, '/login?next=%2Faccount'), '没有给出带返回地址的登录链接');
});

check('带 Cookie GET /account：200、下发写票据、显示用户名', function () use ($hit, $field): void {
    [, , $h] = $hit('POST', '/login', [], ['username' => 'admin', 'password' => 'admin123']);
    $cookie = 'wx_session=' . cookieValue((string) ($h['Set-Cookie'] ?? ''), 'wx_session');
    [$s, $b] = $hit('GET', '/account', [], [], ['Cookie' => $cookie]);
    truthy($s === 200, '账号页应 200，实际 ' . $s);
    truthy(str_contains($b, 'admin'), '没有显示当前用户名');
    truthy($field($b, '_form_ticket') !== '', '没有下发写票据');
});

check('只带 Cookie 提交：401 —— 非 GET 仍不读 Cookie', function () use ($hit): void {
    [, , $h] = $hit('POST', '/login', [], ['username' => 'admin', 'password' => 'admin123']);
    $cookie = 'wx_session=' . cookieValue((string) ($h['Set-Cookie'] ?? ''), 'wx_session');
    [$s, $b] = $hit('POST', '/account', [], ['op' => 'profile', 'display_name' => 'x'],
        ['Cookie' => $cookie]);
    truthy($s === 401, '无票据写请求应 401，实际 ' . $s);
    truthy(str_contains($b, '表单已失效'), '没有说明是票据问题');
});

check('带票据改显示名：303，页面复核真的变了', function () use ($hit, $field): void {
    [, , $h] = $hit('POST', '/login', [], ['username' => 'admin', 'password' => 'admin123']);
    $cookie = 'wx_session=' . cookieValue((string) ($h['Set-Cookie'] ?? ''), 'wx_session');
    [, $b] = $hit('GET', '/account', [], [], ['Cookie' => $cookie]);
    $ticket = $field($b, '_form_ticket');
    [$ps, , $ph] = $hit('POST', '/account', [], [
        'op' => 'profile', 'display_name' => 'Auth Guard', '_form_ticket' => $ticket,
    ], ['Cookie' => $cookie]);
    truthy($ps === 303, '保存应 303，实际 ' . $ps);
    truthy(($ph['Location'] ?? '') === '/account?saved=profile', '303 没有指回账号页');

    [, $after] = $hit('GET', '/account', [], [], ['Cookie' => $cookie]);
    truthy(str_contains($after, 'Auth Guard'), '显示名没有真正保存');
});

check('票据跨路径不可挪用：账号票据提交到审核队列 → 401', function () use ($hit, $field): void {
    [, , $h] = $hit('POST', '/login', [], ['username' => 'admin', 'password' => 'admin123']);
    $cookie = 'wx_session=' . cookieValue((string) ($h['Set-Cookie'] ?? ''), 'wx_session');
    [, $b] = $hit('GET', '/account', [], [], ['Cookie' => $cookie]);
    $ticket = $field($b, '_form_ticket');
    [$s] = $hit('POST', '/console/queue', [], [
        'id' => '1', 'to' => 'hidden', '_form_ticket' => $ticket,
    ], ['Cookie' => $cookie]);
    truthy($s === 401, '跨路径票据被接受，实际 ' . $s);
});

check('注册关闭：GET /register 显示「未开放」，不渲染表单', function () use ($site, $prefix): void {
    $closed = isolatedKernel($site, $prefix, [
        'theme' => ['active' => 'glass-front'],
        'user'  => ['registration' => ['enabled' => false]],
        'permission' => ['cache' => ['driver' => 'memory']],
    ]);
    $closed->boot();
    $r = $closed->handle(new Request('GET', '/register'));
    truthy($r->status() === 200, '注册关闭页应 200，实际 ' . $r->status());
    truthy(str_contains($r->body(), '注册未开放'), '没有说明注册关闭');
    truthy(!str_contains($r->body(), 'action="/register"'), '关闭时仍渲染了注册表单');
});

dropTables($prefix);
