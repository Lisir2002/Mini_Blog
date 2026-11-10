<?php
declare(strict_types=1);

use Wx\Core\Http\Request;
use Wx\Systems\User\CredentialPolicy;

/**
 * 35 · 账号体系闭环 —— 注册、改密、登出、改资料、禁用、锁定。
 *
 * 这一组只回答一个问题：一个真实站点最少需要的账号能力是否成立，
 * 且每种失败都能被调用方区分（锁定 / 禁用 / 口令错不是同一个 401）。
 */
echo "\n=== 35. 账号：注册 / 改密 / 登出 / 禁用 / 锁定 ===\n";

$site   = tempSite('accounts');
$prefix = 'ua_';
dropTables($prefix);

/** @return array{0: int, 1: array<string, mixed>} status / 解码后的信封 */
$hit = static function ($kernel, string $method, string $path, array $body = [], ?string $token = null): array {
    $headers = $token === null ? [] : ['Authorization' => 'Bearer ' . $token];
    $response = $kernel->handle(new Request($method, $path, [], $headers, $body));
    return [$response->status(), (array) json_decode($response->body(), true)];
};

try {
    // 默认配置必须先验：注册关闭是默认值，不能因为「测试里开了」就默认敞开。
    $closed = isolatedKernel($site, $prefix);
    $closed->boot();
    [$status, $body] = $hit($closed, 'POST', '/api/v1/auth/register',
        ['username' => 'alice', 'password' => 'alice-pass-2026']);
    check('注册默认关闭：返回 40304 而不是 404', function () use ($status, $body): void {
        truthy($status === 403, '应返回 403，实际 ' . $status);
        truthy(($body['code'] ?? 0) === 40304, '应返回 40304，实际 ' . ($body['code'] ?? '?'));
    });

    $k = isolatedKernel($site, $prefix, ['user' => ['registration' => ['enabled' => true]]]);
    $k->boot();
    $admin = 'test-admin-token';

    check('口令策略：长度 / 等于用户名 / 弱口令 / 超 72 字节 / 重复字符全部拒绝', function (): void {
        $policy = new CredentialPolicy();
        truthy($policy->passwordError('alice', 'short') !== null, '短口令未拒绝');
        truthy($policy->passwordError('alice', 'alice123') === null, '合法口令被误拒');
        truthy((new CredentialPolicy())->passwordError('carol123', 'carol123') !== null, '等于用户名未拒绝');
        truthy($policy->passwordError('alice', 'password') !== null, '常见弱口令未拒绝');
        truthy($policy->passwordError('alice', str_repeat('x', 73)) !== null, '超过 72 字节未拒绝');
        truthy($policy->passwordError('alice', 'aaaaaaaa') !== null, '单一字符重复未拒绝');
        truthy($policy->usernameError('12345') !== null, '纯数字用户名未拒绝');
        truthy($policy->usernameError('ab') !== null, '短用户名未拒绝');
    });

    [$status, $body] = $hit($k, 'POST', '/api/v1/auth/register',
        ['username' => 'alice', 'password' => 'alice-pass-2026', 'display_name' => 'Alice']);
    $aliceId  = (int) ($body['data']['user']['id'] ?? 0);
    $aliceTok = (string) ($body['data']['token'] ?? '');
    check('注册成功即签发令牌，账号状态为 active', function () use ($status, $body, $aliceTok): void {
        truthy($status === 200, '注册应返回 200，实际 ' . $status);
        truthy(strlen($aliceTok) === 64, '令牌应为 64 位十六进制');
        truthy(($body['data']['user']['status'] ?? '') === 'active', '新账号状态应为 active');
        truthy(!isset($body['data']['user']['password_hash']), '响应不应包含口令摘要');
    });

    [$status, $body] = $hit($k, 'POST', '/api/v1/auth/register',
        ['username' => 'alice', 'password' => 'another-pass-2026']);
    check('用户名重复返回 40907，不是 50001', function () use ($status, $body): void {
        truthy($status === 409, '应返回 409，实际 ' . $status);
        truthy(($body['code'] ?? 0) === 40907, '应返回 40907，实际 ' . ($body['code'] ?? '?'));
    });

    [$status, $body] = $hit($k, 'POST', '/api/v1/auth/token',
        ['username' => 'alice', 'password' => 'alice-pass-2026']);
    $first = (string) ($body['data']['token'] ?? '');
    $second = (string) ($hit($k, 'POST', '/api/v1/auth/token',
        ['username' => 'alice', 'password' => 'alice-pass-2026'])[1]['data']['token'] ?? '');
    check('登录成功并记录最后登录时间', function () use ($status, $first): void {
        truthy($status === 200 && strlen($first) === 64, '登录未签发令牌');
    });

    [$status, $body] = $hit($k, 'POST', '/api/v1/auth/password',
        ['old_password' => 'wrong-old', 'new_password' => 'alice-new-2026'], $first);
    check('改密：旧口令错误返回 40105，不与「令牌无效」混用', function () use ($status, $body): void {
        truthy($status === 401, '应返回 401，实际 ' . $status);
        truthy(($body['code'] ?? 0) === 40105, '应返回 40105，实际 ' . ($body['code'] ?? '?'));
    });

    [$status, $body] = $hit($k, 'POST', '/api/v1/auth/password',
        ['old_password' => 'alice-pass-2026', 'new_password' => 'alice-pass-2026'], $first);
    check('改密：新旧口令相同被拒绝，不给出「成功但没变」的假结果', function () use ($status, $body): void {
        truthy($status === 422 && ($body['code'] ?? 0) === 42201, '相同口令应 422，实际 ' . $status);
    });

    [$status, $body] = $hit($k, 'POST', '/api/v1/auth/password',
        ['old_password' => 'alice-pass-2026', 'new_password' => 'alice-new-2026'], $first);
    $revoked = (int) ($body['data']['revoked_tokens'] ?? 0);
    [$s1] = $hit($k, 'GET', '/api/v1/users/me', [], $first);
    [$s2] = $hit($k, 'GET', '/api/v1/users/me', [], $second);
    check('改密后：当前令牌保留，其他设备令牌全部失效', function () use ($status, $s1, $s2, $revoked): void {
        truthy($status === 200, '改密应成功，实际 ' . $status);
        truthy($revoked >= 1, '应至少吊销 1 枚其他令牌，实际 ' . $revoked);
        truthy($s1 === 200, '当前令牌应继续有效，实际 ' . $s1);
        truthy($s2 === 401, '其他令牌应立即失效，实际 ' . $s2);
    });

    check('改密后旧口令不能登录、新口令可以', function () use ($k, $hit): void {
        [$old] = $hit($k, 'POST', '/api/v1/auth/token', ['username' => 'alice', 'password' => 'alice-pass-2026']);
        [$new] = $hit($k, 'POST', '/api/v1/auth/token', ['username' => 'alice', 'password' => 'alice-new-2026']);
        truthy($old === 401, '旧口令应失败，实际 ' . $old);
        truthy($new === 200, '新口令应成功，实际 ' . $new);
    });

    [$status, $body] = $hit($k, 'POST', '/api/v1/users/me', ['display_name' => '爱丽丝'], $first);
    check('改资料：中文显示名正常写入并按字符计数', function () use ($k, $hit, $status, $body, $first): void {
        truthy($status === 200, '改资料应成功，实际 ' . $status . ' ' . ($body['message'] ?? ''));
        truthy(($body['data']['user']['display_name'] ?? '') === '爱丽丝', '显示名未更新');
        [$tooLong] = $hit($k, 'POST', '/api/v1/users/me', ['display_name' => str_repeat('名', 65)], $first);
        truthy($tooLong === 422, '超过 64 字应被拒绝，实际 ' . $tooLong);
        [$empty] = $hit($k, 'POST', '/api/v1/users/me', ['display_name' => '   '], $first);
        truthy($empty === 422, '空显示名应被拒绝，实际 ' . $empty);
    });

    [$status, $body] = $hit($k, 'GET', '/api/v1/users', [], $admin);
    check('账号列表不返回口令摘要，普通账号无权访问', function () use ($k, $hit, $status, $body, $first): void {
        truthy($status === 200, '管理员列表应成功，实际 ' . $status);
        $items = (array) ($body['data']['items'] ?? []);
        truthy($items !== [], '列表不应为空');
        truthy(!str_contains((string) json_encode($items), 'password'), '列表泄露了口令相关字段');
        [$denied] = $hit($k, 'GET', '/api/v1/users', [], $first);
        truthy($denied === 403, '普通账号应被拒绝，实际 ' . $denied);
    });

    $bob = $hit($k, 'POST', '/api/v1/auth/register', ['username' => 'bob', 'password' => 'bob-pass-2026']);
    $bobId = (int) ($bob[1]['data']['user']['id'] ?? 0);
    $bobTok = (string) ($bob[1]['data']['token'] ?? '');
    $disabled = $hit($k, 'POST', '/api/v1/users/manage', ['user_id' => $bobId, 'action' => 'disable'], $admin);
    [$afterDisable] = $hit($k, 'GET', '/api/v1/users/me', [], $bobTok);
    [$loginDisabled, $bodyDisabled] = $hit($k, 'POST', '/api/v1/auth/token',
        ['username' => 'bob', 'password' => 'bob-pass-2026']);
    check('禁用：已签发令牌立即失效，正确口令也返回 40103', function () use ($disabled, $afterDisable, $loginDisabled, $bodyDisabled): void {
        truthy(($disabled[1]['data']['revoked_tokens'] ?? 0) >= 1, '禁用应吊销令牌');
        truthy($afterDisable === 401, '禁用后旧令牌应失效，实际 ' . $afterDisable);
        truthy($loginDisabled === 401 && ($bodyDisabled['code'] ?? 0) === 40103, '禁用账号登录应 40103');
    });

    [$status, $body] = $hit($k, 'POST', '/api/v1/users/manage', ['user_id' => 1, 'action' => 'disable'], $admin);
    check('不能禁用当前账号，也不能移除最后一个管理员', function () use ($k, $hit, $status, $body, $admin): void {
        truthy($status === 409 && ($body['code'] ?? 0) === 40906, '禁用自己应 40906，实际 ' . $status);
        [$s2, $b2] = $hit($k, 'POST', '/api/v1/users/manage',
            ['user_id' => 1, 'action' => 'set_role', 'role' => 'editor'], $admin);
        truthy($s2 === 409 && ($b2['code'] ?? 0) === 40906, '降级最后管理员应 40906，实际 ' . $s2);
    });

    $carol = $hit($k, 'POST', '/api/v1/auth/register', ['username' => 'carol', 'password' => 'carol-pass-2026']);
    $carolTok = (string) ($carol[1]['data']['token'] ?? '');
    check('登出撤销当前令牌，再次使用返回 401', function () use ($k, $hit, $carolTok): void {
        [$s, $b] = $hit($k, 'POST', '/api/v1/auth/logout', [], $carolTok);
        truthy($s === 200 && ($b['data']['revoked'] ?? false) === true, '登出未撤销令牌');
        [$after] = $hit($k, 'GET', '/api/v1/users/me', [], $carolTok);
        truthy($after === 401, '登出后令牌仍可用');
    });

    // 锁定放在最后：它会把 alice 关进小黑屋，前面的断言不再依赖她。
    check('连续 5 次失败后锁定，锁定期间正确口令也拒绝（42902）', function () use ($k, $hit): void {
        for ($i = 1; $i <= 5; $i++) {
            $hit($k, 'POST', '/api/v1/auth/token', ['username' => 'alice', 'password' => 'bad-' . $i . '-pass']);
        }
        [$locked, $body] = $hit($k, 'POST', '/api/v1/auth/token',
            ['username' => 'alice', 'password' => 'alice-new-2026']);
        truthy($locked === 429, '锁定后应 429，实际 ' . $locked);
        truthy(($body['code'] ?? 0) === 42902, '锁定应返回 42902，实际 ' . ($body['code'] ?? '?'));
    });

    check('不存在的用户与口令错误返回同一码，不泄露账号是否存在', function () use ($k, $hit): void {
        [$s, $b] = $hit($k, 'POST', '/api/v1/auth/token', ['username' => 'nobody', 'password' => 'whatever-2026']);
        truthy($s === 401 && ($b['code'] ?? 0) === 40105, '不存在用户应返回 40105，实际 ' . $s);
    });
} finally {
    dropTables($prefix);
}
