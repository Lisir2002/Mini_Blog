<?php
declare(strict_types=1);

use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Http\Request;
use Wx\Systems\Security\SessionManager;

/**
 * 64 · 会话收口与消费矩阵漏报修正
 *
 * 两件事从 HTTP 与矩阵两面各证一遍：
 *  A. 消费矩阵不再漏报 —— /console/system 早已逐项调用 system.health/layers/contracts，
 *     但「'system.' . $key」拼接让扫描器看不见；显式字面量化后矩阵如实记账。
 *     content.stats（内容规模）与 auth.session.revoke / auth.logout（会话收口）同批离开零消费。
 *  B. 登出与换号登录走会话接口 ——
 *     1. 登出撤销动作走 auth.session.revoke（只撤 kind=session，API 脚本令牌不受影响），
 *        Cookie 清除是无条件的，revoked=false 是幂等结果不是失败；
 *     2. 换号登录前把残留 Cookie 指向的旧凭据走 auth.logout 撤掉，旧令牌不再继续有效。
 *  剩下的零消费只允许是 lifecycle / release 元层、permission.override.grant（刻意）
 *  与 state.task.status —— 任何业务接口出现在清单里都是漏报或假空态。
 */
echo "\n=== 64. 会话收口与消费矩阵 ===\n";

$site   = tempSite('session-runtime');
$prefix = 'q64_';
dropTables($prefix);
$k = isolatedKernel($site, $prefix, [
    'theme' => ['active' => 'glass'],
    'permission' => ['cache' => ['driver' => 'memory']],
    'user' => ['registration' => ['enabled' => true]],
]);
$k->boot();

$hit = static function (string $method, string $path, array $q = [], array $body = [],
    array $headers = []) use ($k): array {
    $res = $k->handle(new Request($method, $path, $q, $headers, $body));
    return [$res->status(), $res->body(), $res->headers()];
};
$field = static function (string $html, string $name): string {
    return preg_match('/name="' . preg_quote($name, '/') . '"[^>]*value="([^"]*)"/', $html, $m)
        ? (string) $m[1] : '';
};
$registry = $k->container()->resolve(ApiRegistryInterface::class);
/** 不直接查库：撤销与否经 SessionManager::describe 判定（revoked 行不再返回元数据）。 */
$sessions = $k->container()->resolve(SessionManager::class);
$aliveOf = static fn(string $token): bool => $sessions->describe(hash('sha256', $token)) !== null;
$login = static function (string $user, string $pass, string $oldCookie = '')
    use ($hit): array {
    [, , $h] = $hit('POST', '/login', [],
        ['username' => $user, 'password' => $pass],
        $oldCookie === '' ? [] : ['Cookie' => $oldCookie]);
    $token = cookieValue((string) ($h['Set-Cookie'] ?? ''), 'wx_session');
    return [$token, $token === '' ? '' : 'wx_session=' . $token];
};
$tokenHash = static fn(string $token): string => hash('sha256', $token);

// 造第二个账号：换号登录要证明「A 的令牌被撤、B 正常进入」。
$registry->execute('auth.register',
    ['username' => 'bob', 'password' => 'bob-pass-99', 'display_name' => '乙']);

check('消费矩阵：漏报修正 —— 五个接口离开零消费，零消费只剩元层与刻意保留',
    function () use ($root): void {
        $json = (array) json_decode((string) shell_exec(
            'php ' . escapeshellarg($root . '/tools/consumers.php') . ' --json'), true);
        $rows = (array) ($json['all'] ?? []);
        // v0.48 起 zero 是「未登记零消费」桶；刻意保留在 deliberate_zero 单列。
        $zero = array_values(array_filter(array_keys(array_filter($rows,
            static fn(array $r): bool => ($r['kind'] ?? 'none') === 'none')),
            static fn(string $op): bool => !isset($json['deliberate'][$op])));
        foreach (['system.health', 'system.layers', 'system.contracts', 'content.stats',
            'auth.session.revoke', 'auth.logout'] as $op) {
            $row = (array) ($rows[$op] ?? []);
            truthy(($row['kind'] ?? 'none') !== 'none',
                $op . ' 仍是零消费：漏报没修干净');
            truthy(!in_array($op, $zero, true), $op . ' 已消费却还在零消费清单里');
        }
        truthy(count($zero) === 0, '未登记零消费应只剩 0，实际 ' . count($zero));
        truthy(count((array) ($json['deliberate_zero'] ?? [])) === 6,
            '刻意零消费登记应覆盖 6 个接口');
        foreach ($zero as $op) {
            truthy((bool) preg_match('/^(lifecycle\.|release\.|permission\.override\.grant|state\.task\.status)/',
                (string) $op), '零消费清单里出现了业务接口：' . $op);
        }
    });

check('普通登录不受影响：签发会话、令牌未撤销',
    function () use ($login, $aliveOf, $hit): void {
        [$token, $cookie] = $login('admin', 'admin123');
        truthy($token !== '', '登录没拿到会话 Cookie');
        truthy($aliveOf($token), '新令牌不该是被撤销状态');
        [$s] = $hit('GET', '/account', [], [], ['Cookie' => $cookie]);
        truthy($s === 200, '带着新会话访问 /account 应 200，实际 ' . $s);
    });

check('换号登录撤残留：A 的旧令牌被撤销，B 正常进入',
    function () use ($login, $aliveOf, $hit): void {
        [, $cookieA] = $login('admin', 'admin123');
        truthy($cookieA !== '', 'A 没登录上');
        [$tokenB, $cookieB] = $login('bob', 'bob-pass-99', $cookieA);
        truthy($tokenB !== '', '带旧 Cookie 登录 B 失败');
        truthy($aliveOf($tokenB), 'B 的新令牌不该被撤');
        [$s] = $hit('GET', '/account', [], [], ['Cookie' => $cookieB]);
        truthy($s === 200, 'B 登录后访问 /account 应 200，实际 ' . $s);
    });

check('残留令牌确实失效：撤掉后再用它访问受保护页 401，也不误伤同账号其他设备',
    function () use ($login, $aliveOf, $hit): void {
        [$tokenA, $cookieA] = $login('admin', 'admin123');
        // A 的「另一台设备」：同账号第二个会话，换号登录不该波及它。
        [$tokenA2, $cookieA2] = $login('admin', 'admin123');
        truthy($aliveOf($tokenA2), '第二台设备的会话应有效');
        // 直接拿 A 第一枚令牌登录 B，再验 A 的残留会话失效。
        $login('bob', 'bob-pass-99', $cookieA);
        truthy(!$aliveOf($tokenA), '残留令牌没有在撤销后失效');
        [$s] = $hit('GET', '/account', [], [], ['Cookie' => $cookieA]);
        truthy($s === 401, '被撤的旧会话访问 /account 应 401，实际 ' . $s);
        truthy($aliveOf($tokenA2), '换号登录撤掉了 A 的其他设备会话');
    });

check('登出走 auth.session.revoke：303、清 Cookie、令牌立即失效',
    function () use ($hit, $field, $aliveOf, $login): void {
        [$token, $cookie] = $login('admin', 'admin123');
        [, $page] = $hit('GET', '/account', [], [], ['Cookie' => $cookie]);
        $ticket = $field($page, '_form_ticket');
        truthy($ticket !== '', '没拿到 /account 的表单票据');
        [$s, , $h] = $hit('POST', '/account', [],
            ['op' => 'logout', '_form_ticket' => $ticket], ['Cookie' => $cookie]);
        truthy($s === 303, '登出应 303，实际 ' . $s);
        $clear = (string) ($h['Set-Cookie'] ?? '');
        truthy($clear !== '' && (str_contains($clear, 'Max-Age=0') || str_contains($clear, 'expires=')),
            '登出响应没有清 Cookie 的 Set-Cookie');
        truthy(!$aliveOf($token), '会话令牌没有被撤销');
        [$s2] = $hit('GET', '/account', [], [], ['Cookie' => $cookie]);
        truthy($s2 === 401, '登出后旧 Cookie 应 401，实际 ' . $s2);
    });

check('幂等登出：令牌已撤时 revoked=false，Cookie 照清、照 303，不报错',
    function () use ($hit, $field, $login, $aliveOf): void {
        [$token, $cookie] = $login('bob', 'bob-pass-99');
        // 票据是 POST 的方法边界（与权限无关），登出也要带：先 GET 拿一张。
        [, $page] = $hit('GET', '/account', [], [], ['Cookie' => $cookie]);
        $ticket = $field($page, '_form_ticket');
        truthy($ticket !== '', '没拿到 /account 的表单票据');
        [$s1] = $hit('POST', '/account', [], ['op' => 'logout', '_form_ticket' => $ticket],
            ['Cookie' => $cookie]);
        truthy($s1 === 303, '第一次登出应 303');
        truthy(!$aliveOf($token), '第一次登出应撤销令牌');
        [$s2] = $hit('POST', '/account', [], ['op' => 'logout', '_form_ticket' => $ticket],
            ['Cookie' => $cookie]);
        truthy($s2 === 303, '重复登出仍应 303（幂等），实际 ' . $s2);
        truthy(!$aliveOf($token), '重复登出后令牌应保持失效');
    });

check('登出不伤 API 脚本令牌：本轮会话撤掉，静态令牌照常工作',
    function () use ($hit, $field, $login, $aliveOf, $registry): void {
        $issued = $registry->execute('auth.token', ['username' => 'admin', 'password' => 'admin123']);
        $apiToken = (string) ($issued['token'] ?? '');
        truthy($apiToken !== '', '没拿到静态令牌');
        [$token, $cookie] = $login('admin', 'admin123');
        [, $page] = $hit('GET', '/account', [], [], ['Cookie' => $cookie]);
        $ticket = $field($page, '_form_ticket');
        $hit('POST', '/account', [], ['op' => 'logout', '_form_ticket' => $ticket],
            ['Cookie' => $cookie]);
        truthy(!$aliveOf($token), '本轮会话没有被撤销');
        [$s] = $hit('GET', '/api/v1/users/me', [], [], bearer($apiToken));
        truthy($s === 200, '静态令牌被登出误伤，实际 ' . $s);
    });

check('/console/system 内容规模上页：content.stats 的 published/total 可见',
    function () use ($hit): void {
        $registry = $GLOBALS['registry'];
        $registry->execute('content.create', ['slug' => 'q64-sys-a', 'title' => '已发',
            'body' => '正文', 'status' => 'published', 'author' => 'admin']);
        $registry->execute('content.create', ['slug' => 'q64-sys-b', 'title' => '草稿',
            'body' => '正文', 'status' => 'draft', 'author' => 'admin']);
        [$s, $page] = $hit('GET', '/console/system', [], [],
            bearer('test-admin-token'));
        truthy($s === 200, '/console/system 应 200，实际 ' . $s);
        truthy(str_contains($page, '内容规模'), '内容规模区块没上页');
        truthy((bool) preg_match('/已发布 \d+ 篇 · 全部 \d+ 篇（含草稿）/', $page),
            'published/total 口径没写清');
    });
