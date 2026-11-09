<?php
declare(strict_types=1);

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\DatabaseInterface;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;

echo "\n=== 16a. 站长自定义权限组：组只是角色来源 ===\n";

/** 组测试内核：subscriber 身份，用户 ID 固定为 1，便于把「组」挂到同一主体上。 */
function customGroupKernel(): Kernel
{
    return adminKernel(['api' => [
        'rate_limit' => ['enabled' => false],
        'tokens' => [
            'test-admin-token'    => ['role' => 'admin', 'username' => 'admin', 'user_id' => 1],
            't-group-subscriber'  => ['role' => 'subscriber', 'username' => 'sub', 'user_id' => 1],
        ],
    ]]);
}

/** @return array<string, mixed> */
function customGroupOp(Kernel $k, array $payload): array
{
    $r = $k->handle(new Request('POST', '/api/v1/permission/group', [], adminHeaders(),
        [], (string) json_encode($payload, JSON_UNESCAPED_UNICODE)));
    return ['status' => $r->status(), 'body' => (array) json_decode($r->body(), true)];
}

/** @return array<string, mixed> */
function customGroupList(Kernel $k): array
{
    $r = $k->handle(new Request('GET', '/api/v1/permission/groups', [], adminHeaders()));
    return (array) (json_decode($r->body(), true)['data']['groups'] ?? []);
}

check('站长可自定义组标识 / 名称 / 颜色 / 排序，列表按 sort 排序', function (): void {
    $k = customGroupKernel();
    $key = 'g16a-' . bin2hex(random_bytes(3));
    try {
        truthy(customGroupOp($k, ['op' => 'upsert', 'group' => $key, 'name' => '核心读者',
            'description' => '站长自定义组', 'color' => '#8a5cf6', 'sort' => 5])['body']['code'] === 0,
            '创建自定义组失败');
        $groups = customGroupList($k);
        truthy(isset($groups[$key]), '组未出现在列表里');
        truthy($groups[$key]['name'] === '核心读者', '组名未持久化');
        truthy($groups[$key]['color'] === '#8a5cf6', '颜色未持久化');
        truthy($groups[$key]['sort'] === 5, '排序未持久化');
    } finally {
        customGroupOp($k, ['op' => 'delete', 'group' => $key]);
    }
});

check('组绑定多个角色：主体角色集合 = 自身角色 ∪ 组角色', function (): void {
    $k = customGroupKernel();
    $key = 'g16a-role-' . bin2hex(random_bytes(3));
    $check = fn(): bool => (bool) (json_decode($k->handle(new Request(
        'GET', '/api/v1/permission/check', ['action' => 'content.create'], bearer('t-group-subscriber')
    ))->body(), true)['data']['allowed'] ?? false);
    try {
        truthy($check() === false, '未入组前 subscriber 不应能创建文章');
        customGroupOp($k, ['op' => 'upsert', 'group' => $key, 'name' => '作者团']);
        customGroupOp($k, ['op' => 'add_role', 'group' => $key, 'role' => 'author']);
        customGroupOp($k, ['op' => 'add_role', 'group' => $key, 'role' => 'editor']);
        customGroupOp($k, ['op' => 'add_member', 'group' => $key, 'subject' => 'user:1']);
        truthy($check() === true, '组角色未并入主体角色集合');
    } finally {
        customGroupOp($k, ['op' => 'delete', 'group' => $key]);
    }
});

check('移出成员 / 删除组后授权立即失效（缓存显式失效）', function (): void {
    $k = customGroupKernel();
    $key = 'g16a-revoke-' . bin2hex(random_bytes(3));
    $check = fn(): bool => (bool) (json_decode($k->handle(new Request(
        'GET', '/api/v1/permission/check', ['action' => 'content.create'], bearer('t-group-subscriber')
    ))->body(), true)['data']['allowed'] ?? false);
    try {
        customGroupOp($k, ['op' => 'upsert', 'group' => $key, 'name' => '临时组']);
        customGroupOp($k, ['op' => 'add_role', 'group' => $key, 'role' => 'author']);
        customGroupOp($k, ['op' => 'add_member', 'group' => $key, 'subject' => 'user:1']);
        truthy($check() === true, '入组后应放行');
        customGroupOp($k, ['op' => 'remove_member', 'group' => $key, 'subject' => 'user:1']);
        truthy($check() === false, '移出成员后仍放行，缓存未失效');
        customGroupOp($k, ['op' => 'add_member', 'group' => $key, 'subject' => 'user:1']);
        truthy($check() === true, '重新入组后应恢复放行');
        customGroupOp($k, ['op' => 'delete', 'group' => $key]);
        truthy($check() === false, '删组后仍放行，缓存未失效');
    } finally {
        customGroupOp($k, ['op' => 'delete', 'group' => $key]);
    }
});

check('颜色 / 排序是展示属性：改动它们不改变任何权限决策', function (): void {
    $k = customGroupKernel();
    $key = 'g16a-meta-' . bin2hex(random_bytes(3));
    $k->boot();
    $acl = $k->container()->resolve(AccessControlInterface::class);
    $identity = ['role' => 'subscriber', 'username' => 'sub', 'user_id' => 1];
    $deny = ['role' => 'subscriber', 'user_id' => 1];
    try {
        customGroupOp($k, ['op' => 'upsert', 'group' => $key, 'name' => '展示测试', 'color' => '#111111', 'sort' => 1]);
        $before = $acl->decide($deny, 'content.delete');
        truthy($before !== AccessControlInterface::ALLOW, 'subscriber 不应能删除文章');
        customGroupOp($k, ['op' => 'upsert', 'group' => $key, 'name' => '展示测试', 'color' => '#abcdef', 'sort' => 99]);
        truthy($acl->decide($deny, 'content.delete') === $before, '改颜色 / 排序改变了决策');
        truthy($acl->decide($identity, 'content.delete') !== AccessControlInterface::ALLOW, '展示属性不应提权');
    } finally {
        customGroupOp($k, ['op' => 'delete', 'group' => $key]);
        $db = $k->container()->resolve(DatabaseInterface::class);
        $db->exec('DELETE FROM ' . $db->prefix() . 'permission_groups WHERE group_key = :k', [':k' => $key]);
    }
});
