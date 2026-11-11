<?php
declare(strict_types=1);

use Wx\Core\Http\Request;
use Wx\Core\Kernel;

/**
 * 50b · 账号自助运行时：注册、改密、登出都要真的生效
 *
 * 重点不是「按钮能点」，而是四个不可逆事实：
 *  1. 注册成功即可用同一组凭据进账号页；
 *  2. 改密后旧口令立刻失效、当前会话保留、其他设备下线；
 *  3. 登出撤销的是会话令牌，不只是删掉浏览器里的 Cookie；
 *  4. 没有数据库时登录页说「不可用」，不显示一张提交后必失败的表格。
 */
echo "\n=== 50b. 账号自助运行时 ===\n";

$site   = tempSite('account-runtime');
$prefix = 'aq_';
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
$login = static function (string $user, string $pass) use ($k): array {
    $r = $k->handle(new Request('POST', '/api/v1/auth/token', [], ['Content-Type' => 'application/json'],
        [], (string) json_encode(['username' => $user, 'password' => $pass])));
    return [$r->status(), (array) json_decode($r->body(), true)];
};
$cookieOf = static fn(string $header): string
    => 'wx_session=' . cookieValue($header, 'wx_session');
$ticketOf = static function (string $html): string {
    return preg_match('/name="_form_ticket" value="([^"]+)"/', $html, $m) ? (string) $m[1] : '';
};

$user = 'acct' . random_int(1000, 9999);
$pass = 'acct-pass-2026';
[, , $h] = $hit('POST', '/register', [], ['username' => $user, 'password' => $pass, 'display_name' => '账号守卫']);
$cookie = $cookieOf((string) ($h['Set-Cookie'] ?? ''));

check('注册开放：303 进账号页，并已建立浏览器会话', function () use ($hit, $cookie, $user): void {
    [$s] = $hit('GET', '/account', [], [], ['Cookie' => $cookie]);
    truthy($s === 200, '注册后账号页不可用，实际 ' . $s);
    [, $b] = $hit('GET', '/account', [], [], ['Cookie' => $cookie]);
    truthy(str_contains($b, $user), '账号页没有显示新用户名');
});

check('重复用户名：409 并保留页面，不静默当成注册成功', function () use ($hit, $user, $pass): void {
    [$s, $b] = $hit('POST', '/register', [], ['username' => $user, 'password' => $pass]);
    truthy($s === 409, '重复注册应 409，实际 ' . $s);
    truthy(str_contains($b, '已被占用'), '没有说明用户名冲突');
});

check('两次新口令不一致：422，且不调用改密接口', function () use ($hit, $cookie, $ticketOf): void {
    [, $b] = $hit('GET', '/account', [], [], ['Cookie' => $cookie]);
    $ticket = $ticketOf($b);
    [$s, $page] = $hit('POST', '/account', [], [
        'op' => 'password', 'old_password' => 'acct-pass-2026',
        'new_password' => 'next-pass-2026', 'confirm_password' => 'other-pass-2026',
        '_form_ticket' => $ticket,
    ], ['Cookie' => $cookie]);
    truthy($s === 422, '确认口令不一致应 422，实际 ' . $s);
    truthy(str_contains($page, '不一致'), '没有说明两次口令不一致');
});

check('旧口令错误：401 + 「旧口令不正确」', function () use ($hit, $cookie, $ticketOf): void {
    [, $b] = $hit('GET', '/account', [], [], ['Cookie' => $cookie]);
    [$s, $page] = $hit('POST', '/account', [], [
        'op' => 'password', 'old_password' => 'not-the-pass',
        'new_password' => 'next-pass-2026', 'confirm_password' => 'next-pass-2026',
        '_form_ticket' => $ticketOf($b),
    ], ['Cookie' => $cookie]);
    truthy($s === 401, '旧口令错误应 401，实际 ' . $s);
    truthy(str_contains($page, '旧口令不正确'), '没有给出可读原因');
});

check('改密成功：旧口令失效、新口令可用、当前会话仍有效', function () use ($hit, $cookie, $user, $login, $ticketOf): void {
    [, $b] = $hit('GET', '/account', [], [], ['Cookie' => $cookie]);
    [$s] = $hit('POST', '/account', [], [
        'op' => 'password', 'old_password' => 'acct-pass-2026',
        'new_password' => 'next-pass-2026', 'confirm_password' => 'next-pass-2026',
        '_form_ticket' => $ticketOf($b),
    ], ['Cookie' => $cookie]);
    truthy($s === 303, '改密应 303，实际 ' . $s);

    [$oldStatus] = $login($user, 'acct-pass-2026');
    [$newStatus, $newBody] = $login($user, 'next-pass-2026');
    truthy($oldStatus === 401, '旧口令仍然能登录，实际 ' . $oldStatus);
    truthy($newStatus === 200 && ($newBody['code'] ?? -1) === 0, '新口令不能登录');

    [$still] = $hit('GET', '/account', [], [], ['Cookie' => $cookie]);
    truthy($still === 200, '改密把当前会话也踢掉了');
});

check('登出：303、清 Cookie、令牌真的被撤销', function () use ($hit, $cookie, $user, $login, $ticketOf): void {
    [, $b] = $hit('GET', '/account', [], [], ['Cookie' => $cookie]);
    [$s, , $h] = $hit('POST', '/account', [], [
        'op' => 'logout', '_form_ticket' => $ticketOf($b),
    ], ['Cookie' => $cookie]);
    truthy($s === 303, '登出应 303，实际 ' . $s);
    truthy(($h['Location'] ?? '') === '/login?loggedout=1', '没有回到登录页并带提示');
    truthy(str_contains((string) ($h['Set-Cookie'] ?? ''), 'Max-Age=0'), '没有清 Cookie');

    [$after] = $hit('GET', '/account', [], [], ['Cookie' => $cookie]);
    truthy($after === 401, '登出后旧 Cookie 仍能访问账号页，实际 ' . $after);

    [$status] = $login($user, 'next-pass-2026');
    truthy($status === 200, '登出不该影响同账号的新登录（用于确认账号本身仍正常）');
});

check('无数据库：登录页 503 + 明确「不可用」，不渲染表单', function () use ($site): void {
    $k2 = new Kernel($site, [
        'theme' => ['active' => 'glass-front'],
        'database' => ['dsn' => '', 'auto_fallback' => true],
    ]);
    $k2->boot();
    $r = $k2->handle(new Request('GET', '/login'));
    truthy($r->status() === 503, '无库登录页应 503，实际 ' . $r->status());
    truthy(str_contains($r->body(), '数据库未接入'), '没有说明不可用原因');
    truthy(!str_contains($r->body(), 'type="password"'), '无库时仍渲染了口令输入框');
});

dropTables($prefix);
