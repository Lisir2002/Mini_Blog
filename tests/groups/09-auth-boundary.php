<?php
declare(strict_types=1);

use Wx\Contracts\DatabaseInterface;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;

echo "\n=== 9. 接口鉴权执行与写接口公网边界 ===\n";

check('未携带令牌访问 admin 接口返回 40101', function (): void {
    $r = kernel(null)->handle(new Request('GET', '/api/v1/lifecycle/units'));
    $body = json_decode($r->body(), true);
    truthy($r->status() === 401 && ($body['code'] ?? null) === 40101, '应返回 40101');
});

check('无效令牌返回 40102，user 级令牌访问 admin 返回 40301', function (): void {
    $bad = kernel(null)->handle(new Request('GET', '/api/v1/lifecycle/units', [], ['Authorization' => 'Bearer nope']));
    truthy((json_decode($bad->body(), true)['code'] ?? null) === 40102, '无效令牌应返回 40102');

    $k = new Kernel(getcwd(), [
        'api' => ['tokens' => ['reader-token' => ['role' => 'user', 'username' => 'reader', 'user_id' => 2]]],
    ]);
    $r = $k->handle(new Request('GET', '/api/v1/lifecycle/units', [], ['Authorization' => 'Bearer reader-token']));
    $body = json_decode($r->body(), true);
    truthy($r->status() === 403 && ($body['code'] ?? null) === 40301, '角色不足应返回 40301');
});

check('有效 admin 令牌可访问受保护接口，users.me 回显身份', function (): void {
    $k = adminKernel();
    $r = $k->handle(new Request('GET', '/api/v1/lifecycle/units', [], adminHeaders()));
    truthy((json_decode($r->body(), true)['code'] ?? null) === 0, 'admin 令牌未通过');
    $me = $k->handle(new Request('GET', '/api/v1/users/me', [], adminHeaders()));
    $data = json_decode($me->body(), true)['data'] ?? [];
    truthy(($data['identity']['username'] ?? null) === 'admin', '身份未注入业务执行者');
});

check('公网来源的写接口被 40302 拒绝；显式开启后放行', function (): void {
    $k = adminKernel();
    $public = new Request('POST', '/api/v1/comments', [], adminHeaders(), [], '{"post_slug":"first-post","body":"hi"}', '203.0.113.9');
    $r = $k->handle($public);
    truthy((json_decode($r->body(), true)['code'] ?? null) === 40302, '公网写接口未拒绝');

    $open = adminKernel(['api' => ['expose_write' => true]]);
    $r2 = $open->handle(new Request('POST', '/api/v1/comments', [], [], [], '{"post_slug":"first-post","body":"hi"}', '203.0.113.9'));
    truthy((json_decode($r2->body(), true)['code'] ?? null) === 0, '显式开启后应放行匿名写');
    $db = $open->container()->resolve(DatabaseInterface::class);
    $db->exec('DELETE FROM ' . $db->prefix() . "comments WHERE post_slug = :s AND body = 'hi'", [':s' => 'first-post']);
});

check('CSP 由配置注入，默认关闭不影响内联样式主题', function (): void {
    $default = kernel('bare')->handle(new Request('GET', '/'));
    truthy(!isset($default->headers()['Content-Security-Policy']), '默认不应输出 CSP');
    $k = new Kernel(getcwd(), ['security' => ['csp' => "default-src 'self'"], 'theme' => ['active' => 'bare']]);
    $r = $k->handle(new Request('GET', '/'));
    truthy(($r->headers()['Content-Security-Policy'] ?? null) === "default-src 'self'", 'CSP 未按配置注入');
});
