<?php
declare(strict_types=1);

use Wx\Contracts\DatabaseInterface;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;

echo "\n=== 15b. 页面会话：同一令牌体系，HttpOnly 载体 ===\n";

function sessionKernel(): Kernel
{
    return adminKernel(['permission' => ['cache' => ['driver' => 'memory']]]);
}

/** @return array{status: int, body: array<string, mixed>, cookie: string} */
function createSession(Kernel $k): array
{
    $payload = ['username' => 'admin', 'password' => 'admin123'];
    $r = $k->handle(new Request(
        'POST', '/api/v1/auth/session', [],
        ['Content-Type' => 'application/json'], [],
        (string) json_encode($payload)
    ));
    return [
        'status' => $r->status(),
        'body'   => (array) json_decode($r->body(), true),
        'cookie' => (string) ($r->headers()['Set-Cookie'] ?? ''),
    ];
}

function sessionToken(string $cookie): string
{
    return cookieValue($cookie, 'wx_session');
}

check('登录签发会话：响应体无令牌明文，Cookie 带 HttpOnly 与 SameSite', function (): void {
    $k = sessionKernel();
    $result = createSession($k);
    truthy($result['status'] === 200 && ($result['body']['code'] ?? null) === 0,
        '会话创建失败：' . json_encode($result['body'], JSON_UNESCAPED_UNICODE));
    truthy(!isset($result['body']['data']['token']), '响应体泄漏了会话令牌明文');
    truthy(($result['body']['data']['kind'] ?? '') === 'session', 'kind 不是 session');
    truthy((int) ($result['body']['data']['max_age'] ?? 0) > 0, '未返回有效期');

    $cookie = $result['cookie'];
    truthy(str_contains($cookie, 'wx_session='), '未写 wx_session Cookie');
    truthy(stripos($cookie, 'HttpOnly') !== false, 'Cookie 缺少 HttpOnly');
    truthy(stripos($cookie, 'SameSite=Lax') !== false, 'Cookie 缺少 SameSite');
    truthy(sessionToken($cookie) !== '', 'Cookie 中取不到令牌');

    $db = $k->container()->resolve(DatabaseInterface::class);
    $stmt = $db->pdo()->prepare(
        'SELECT kind FROM ' . $db->prefix() . 'user_tokens WHERE token_hash = ? LIMIT 1'
    );
    $stmt->execute([hash('sha256', sessionToken($cookie))]);
    $row = $stmt->fetch();
    truthy($row !== false && (string) $row['kind'] === 'session', '库中未标记为会话令牌');
});

check('会话 Cookie 可用于页面 GET，但不是 API 凭据', function (): void {
    $k = sessionKernel();
    $created = createSession($k);
    $cookie = 'wx_session=' . sessionToken($created['cookie']);
    try {
        $page = $k->handle(new Request('GET', '/console', [], ['Cookie' => $cookie]));
        truthy($page->status() === 200, '会话 Cookie 未能访问后台页面');
        $data = (array) json_decode($page->body(), true);
        truthy(($data['identity']['username'] ?? null) === 'admin', '页面身份未贯通');

        $anon = $k->handle(new Request('GET', '/console'));
        truthy($anon->status() === 401, '匿名访问后台未被拒绝');

        $api = $k->handle(new Request('POST', '/api/v1/content/posts', [], [
            'Cookie' => $cookie, 'Content-Type' => 'application/json',
        ], [], '{"title":"x","body":"y","slug":"t15b-cookie"}'));
        truthy($api->status() === 401, 'API 接受了 Cookie 身份（CSRF 面被打开）');
    } finally {
        $db = $k->container()->resolve(DatabaseInterface::class);
        $db->pdo()->prepare('DELETE FROM ' . $db->prefix() . 'user_tokens WHERE token_hash = ?')
            ->execute([hash('sha256', sessionToken($created['cookie']))]);
    }
});

check('会话令牌不能当 Bearer 调 API：页面身份不延伸到接口', function (): void {
    $k = sessionKernel();
    $created = createSession($k);
    $token = sessionToken($created['cookie']);
    try {
        $r = $k->handle(new Request('GET', '/api/v1/permission/roles', [], bearer($token)));
        truthy($r->status() === 401, '会话令牌被接受为普通 API 凭据');
    } finally {
        $db = $k->container()->resolve(DatabaseInterface::class);
        $db->pdo()->prepare('DELETE FROM ' . $db->prefix() . 'user_tokens WHERE token_hash = ?')
            ->execute([hash('sha256', $token)]);
    }
});

check('登出撤销会话：之后页面 401，重复登出幂等', function (): void {
    $k = sessionKernel();
    $created = createSession($k);
    $token = sessionToken($created['cookie']);
    try {
        // 浏览器真实路径：HttpOnly 令牌脚本读不到，登出只能靠 Cookie
        $out = $k->handle(new Request('DELETE', '/api/v1/auth/session', [], ['Cookie' => 'wx_session=' . $token]));
        $body = (array) json_decode($out->body(), true);
        truthy(($body['data']['revoked'] ?? false) === true, '登出未撤销会话');
        truthy(str_contains((string) ($out->headers()['Set-Cookie'] ?? ''), 'Max-Age=0'), '登出未清除 Cookie');

        $page = $k->handle(new Request('GET', '/console', [], ['Cookie' => 'wx_session=' . $token]));
        truthy($page->status() === 401, '撤销后页面仍可访问');

        $again = $k->handle(new Request('DELETE', '/api/v1/auth/session', [], ['Cookie' => 'wx_session=' . $token]));
        truthy($again->status() === 401, '已撤销令牌仍被接受为身份');
    } finally {
        $db = $k->container()->resolve(DatabaseInterface::class);
        $db->pdo()->prepare('DELETE FROM ' . $db->prefix() . 'user_tokens WHERE token_hash = ?')
            ->execute([hash('sha256', $token)]);
    }
});

check('会话过期后立即失效，与 API 令牌同一套过期语义', function (): void {
    $k = sessionKernel();
    $created = createSession($k);
    $token = sessionToken($created['cookie']);
    $db = $k->container()->resolve(DatabaseInterface::class);
    try {
        $db->pdo()->prepare(
            'UPDATE ' . $db->prefix() . "user_tokens SET expires_at = '2000-01-01 00:00:00' WHERE token_hash = ?"
        )->execute([hash('sha256', $token)]);
        $page = $k->handle(new Request('GET', '/console', [], ['Cookie' => 'wx_session=' . $token]));
        truthy($page->status() === 401, '过期会话仍可访问页面');
    } finally {
        $db->pdo()->prepare('DELETE FROM ' . $db->prefix() . 'user_tokens WHERE token_hash = ?')
            ->execute([hash('sha256', $token)]);
    }
});

check('当前会话接口只返回元数据，不回显令牌', function (): void {
    $k = sessionKernel();
    $created = createSession($k);
    $token = sessionToken($created['cookie']);
    try {
        $r = $k->handle(new Request('GET', '/api/v1/auth/session', [], ['Cookie' => 'wx_session=' . $token]));
        $body = (array) json_decode($r->body(), true);
        truthy(($body['code'] ?? null) === 0, '会话查询失败');
        truthy(($body['data']['session']['kind'] ?? '') === 'session', '未报告会话类型');
        truthy(!str_contains($r->body(), $token), '响应体回显了会话令牌');
    } finally {
        $db = $k->container()->resolve(DatabaseInterface::class);
        $db->pdo()->prepare('DELETE FROM ' . $db->prefix() . 'user_tokens WHERE token_hash = ?')
            ->execute([hash('sha256', $token)]);
    }
});
