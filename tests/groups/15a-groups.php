<?php
declare(strict_types=1);

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\DatabaseInterface;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;

echo "\n=== 15a. 主体分组：角色来源扩展，不是第四层权限 ===\n";

/** 三个身份：admin(1) / 组成员(2) / 非成员(3)，都走静态令牌，避免依赖真实登录 */
function groupKernel(): Kernel
{
    return adminKernel(['api' => ['tokens' => [
        'test-admin-token' => ['role' => 'admin', 'username' => 'admin', 'user_id' => 1],
        't-member'         => ['role' => 'subscriber', 'username' => 'member', 'user_id' => 2],
        't-outsider'       => ['role' => 'subscriber', 'username' => 'outsider', 'user_id' => 3],
    ]]]);
}

/** @return array<string, mixed> */
function memberIdentity(int $id, string $name): array
{
    return ['role' => 'subscriber', 'username' => $name, 'user_id' => $id];
}

check('组成员通过组获得角色许可，非成员不受影响', function (): void {
    $k = groupKernel();
    $k->boot();
    $acl = $k->container()->resolve(AccessControlInterface::class);
    $groups = $acl->groups();
    $groups->upsert('t15a-ops', '运营组', '15a 守卫');
    $groups->addRole('t15a-ops', 'editor');
    $groups->addMember('t15a-ops', 'user:2');
    try {
        truthy($acl->allows(memberIdentity(2, 'member'), 'logs.read'), '组成员未获得组角色许可');
        truthy(!$acl->allows(memberIdentity(3, 'outsider'), 'logs.read'), '非成员意外获得许可');
    } finally {
        $groups->delete('t15a-ops');
    }
});

check('撤销组角色 / 删除组后许可立即消失（缓存显式失效）', function (): void {
    $k = groupKernel();
    $k->boot();
    $acl = $k->container()->resolve(AccessControlInterface::class);
    $groups = $acl->groups();
    $groups->upsert('t15a-revoke', '撤销用例', '');
    $groups->addRole('t15a-revoke', 'editor');
    $groups->addMember('t15a-revoke', 'user:2');
    try {
        truthy($acl->allows(memberIdentity(2, 'member'), 'logs.read'), '前置条件失败');
        $groups->removeRole('t15a-revoke', 'editor');
        truthy(!$acl->allows(memberIdentity(2, 'member'), 'logs.read'), '撤销组角色后仍放行');
        $groups->addRole('t15a-revoke', 'editor');
        truthy($acl->allows(memberIdentity(2, 'member'), 'logs.read'), '重新绑定后未恢复');
        $groups->delete('t15a-revoke');
        truthy(!$acl->allows(memberIdentity(2, 'member'), 'logs.read'), '删除组后仍放行');
    } finally {
        $groups->delete('t15a-revoke');
    }
});

check('组不构成提权后门：显式 deny 与策略收紧仍然优先', function (): void {
    $k = groupKernel();
    $k->boot();
    $acl = $k->container()->resolve(AccessControlInterface::class);
    $groups = $acl->groups();
    $groups->upsert('t15a-deny', '拒绝用例', '');
    $groups->addRole('t15a-deny', 'editor');
    $groups->addMember('t15a-deny', 'user:2');
    $db = $k->container()->resolve(DatabaseInterface::class);
    $acl->setAcl([
        'type' => 'post', 'id' => 't15a-post', 'subject' => '*',
        'capability' => 'content.read', 'allow' => false, 'field' => '',
    ]);
    try {
        $deny = $acl->decide(memberIdentity(2, 'member'), 'content.read', ['type' => 'post', 'id' => 't15a-post']);
        truthy($deny === AccessControlInterface::DENY, '组角色许可压过了显式 deny：' . $deny);

        $k->hooks()->filter('permission.decide', static function (string $v): string {
            return $v === AccessControlInterface::ALLOW ? AccessControlInterface::DENY : $v;
        }, 99);
        truthy(!$acl->allows(memberIdentity(2, 'member'), 'logs.read'), '策略钩子未能收紧组带来的许可');
    } finally {
        $groups->delete('t15a-deny');
        $db->pdo()->prepare('DELETE FROM ' . $db->prefix() . 'permission_acl WHERE resource_id = ?')
            ->execute(['t15a-post']);
    }
});

check('分组接口贯通：写入、查询、删除都走同一套权限契约', function (): void {
    $k = groupKernel();
    $send = static function (Kernel $kernel, string $method, string $path, array $body): array {
        $r = $kernel->handle(new Request(
            $method, $path, [], adminHeaders() + ['Content-Type' => 'application/json'],
            [], (string) json_encode($body, JSON_UNESCAPED_UNICODE)
        ));
        return ['status' => $r->status(), 'data' => (array) json_decode($r->body(), true)];
    };

    try {
        $created = $send($k, 'POST', '/api/v1/permission/group', [
            'op' => 'upsert', 'group' => 't15a-api', 'name' => '接口组', 'description' => '15a',
        ]);
        truthy(($created['data']['code'] ?? null) === 0, 'upsert 失败：' . json_encode($created, JSON_UNESCAPED_UNICODE));

        $added = $send($k, 'POST', '/api/v1/permission/group', [
            'op' => 'add_role', 'group' => 't15a-api', 'role' => 'editor',
        ]);
        truthy(($added['data']['data']['changed'] ?? false) === true, '绑定角色未生效');

        $list = $k->handle(new Request('GET', '/api/v1/permission/groups', [], adminHeaders()));
        $rows = (array) json_decode($list->body(), true);
        $group = $rows['data']['groups']['t15a-api'] ?? null;
        truthy(is_array($group) && in_array('editor', $group['roles'], true), '列表未反映组角色');
    } finally {
        $send($k, 'POST', '/api/v1/permission/group', ['op' => 'delete', 'group' => 't15a-api']);
    }

    $after = $k->handle(new Request('GET', '/api/v1/permission/groups', [], adminHeaders()));
    $rows = (array) json_decode($after->body(), true);
    truthy(!isset($rows['data']['groups']['t15a-api']), '删除后仍出现在列表里');
});
