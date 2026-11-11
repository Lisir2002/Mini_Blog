<?php
declare(strict_types=1);

use Wx\Contracts\DatabaseInterface;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Systems\Permission\PermissionRegistry;

echo "\n=== 16e. 成员层贯通：资料装配、可停用、无库回退、权限点自报 ===\n";

/** @return array<string, mixed> */
function integGet(Kernel $k, string $path, array $query = [], array $headers = []): array
{
    return (array) json_decode($k->handle(new Request('GET', $path, $query, $headers))->body(), true);
}

/** @return array<string, mixed> */
function integPost(Kernel $k, string $path, array $payload, array $headers): array
{
    $r = $k->handle(new Request('POST', $path, [], $headers + ['Content-Type' => 'application/json'],
        [], (string) json_encode($payload, JSON_UNESCAPED_UNICODE)));
    return ['status' => $r->status(), 'body' => (array) json_decode($r->body(), true)];
}

check('users.me 装配成长快照：经验 / 等级 / 头衔随身份一并返回', function (): void {
    $k = adminKernel();
    $k->boot();
    $me = integGet($k, '/api/v1/users/me', [], adminHeaders());
    $member = $me['data']['identity']['member'] ?? null;
    truthy(is_array($member), 'users.me 未装配 member 快照');
    truthy(array_key_exists('xp', $member) && array_key_exists('level', $member), '快照缺少经验或等级');
    truthy(is_array($member['titles'] ?? null), '快照缺少头衔列表');
    truthy(($me['data']['identity']['username'] ?? '') === 'admin', '身份字段被成员层破坏');
});

check('成员层可停用：users.me 不再有 member 键，成长接口返回 50101，正文写入不受影响', function (): void {
    $k = adminKernel(['api' => ['rate_limit' => ['enabled' => false]], 'systems' => ['disabled' => ['member']]]);
    $me = integGet($k, '/api/v1/users/me', [], adminHeaders());
    truthy(!isset($me['data']['identity']['member']), '停用后仍装配了 member 快照');

    $profile = integGet($k, '/api/v1/member/profile', ['user_id' => '1']);
    truthy(($profile['code'] ?? null) === 50101, '停用后成员接口未规范化为 50101');

    $slug = 't16e-off-' . bin2hex(random_bytes(3));
    $created = integPost($k, '/api/v1/content/posts', [
        'slug' => $slug, 'title' => '停用成员层', 'body' => '正文', 'status' => 'draft',
    ], adminHeaders());
    try {
        truthy(($created['body']['code'] ?? null) === 0, '停用成员层后正文写入失败');
    } finally {
        $db = $k->container()->resolve(DatabaseInterface::class);
        $db->exec('DELETE FROM ' . $db->prefix() . 'content_posts WHERE slug = :s', [':s' => $slug]);
    }
});

check('拔掉数据库：等级表回退内存基线，档案不编造（40403），写接口 fail-closed', function (): void {
    global $root;
    $k = new Kernel($root, [
        'database' => ['dsn' => '', 'auto_fallback' => true],
        'api'      => ['rate_limit' => ['enabled' => false],
                       'tokens' => ['test-admin-token' => ['role' => 'admin', 'username' => 'admin', 'user_id' => 1]]],
    ]);
    // v0.41 起「查不到人」一律 40403：无库时用户表也读不到，与其返回一份
    // 0 经验 / Lv.1 的空档案（看起来像真用户），不如如实说查不到。
    $profile = integGet($k, '/api/v1/member/profile', ['user_id' => '1']);
    truthy(($profile['code'] ?? null) === 40403, '无库时仍编造了成长档案');

    $levels = integGet($k, '/api/v1/member/levels');
    truthy(count((array) ($levels['data']['levels'] ?? [])) >= 3, '无库时未提供基线等级表');

    $write = integPost($k, '/api/v1/member/xp/award', [
        'user_id' => '1', 'action' => 'manual.adjust', 'event_key' => 't16e-nodb', 'amount' => 10,
    ], adminHeaders());
    truthy(($write['body']['code'] ?? null) === 40303, '无库时写接口未 fail-closed');
});

check('权限点自报：六个成员权限点均被收录，撤销 admin 通配符后写接口返回 40303', function (): void {
    global $root;
    $k = adminKernel(['api' => ['rate_limit' => ['enabled' => false]]]);
    $registry = new PermissionRegistry($root);
    foreach (['member.read', 'member.xp.award', 'member.xp.rule.manage', 'member.title.manage', 'member.title.grant', 'member.level.manage'] as $perm) {
        truthy($registry->has($perm), '权限点未自报：' . $perm);
    }

    // 经权限决策器自身撤销 / 恢复：授权变更必须走同一个入口，缓存才会显式失效。
    // 直接改库会让缓存读到过期授权（这也是产品层面的约束，不只是测试写法）。
    $k->boot();
    $acl = $k->container()->resolve(\Wx\Contracts\AccessControlInterface::class);
    $acl->revoke('admin', '*');
    try {
        $denied = integPost($k, '/api/v1/member/xp/award', [
            'user_id' => '1', 'action' => 'manual.adjust', 'event_key' => 't16e-perm', 'amount' => 1,
        ], adminHeaders());
        truthy(($denied['body']['code'] ?? null) === 40303, '缺少权限点时未 fail-closed');
    } finally {
        $acl->grant('admin', '*');
    }
});
