<?php
declare(strict_types=1);

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\DatabaseInterface;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;

echo "\n=== 16b. 头衔：站长定义、手动授予、等级自动授予，且不产生权限 ===\n";

function memberKernel(array $extra = []): Kernel
{
    return adminKernel(array_replace_recursive([
        'api' => [
            'rate_limit' => ['enabled' => false],
            'tokens' => [
                'test-admin-token'     => ['role' => 'admin', 'username' => 'admin', 'user_id' => 1],
                't-member-subscriber'  => ['role' => 'subscriber', 'username' => 'sub', 'user_id' => 2],
            ],
        ],
    ], $extra));
}

/** @return array<string, mixed> */
function memberPost(Kernel $k, string $path, array $payload, array $headers): array
{
    $r = $k->handle(new Request('POST', $path, [], $headers + ['Content-Type' => 'application/json'],
        [], (string) json_encode($payload, JSON_UNESCAPED_UNICODE)));
    return ['status' => $r->status(), 'body' => (array) json_decode($r->body(), true)];
}

/** @return array<string, mixed> */
function memberGet(Kernel $k, string $path, array $query = [], array $headers = []): array
{
    $r = $k->handle(new Request('GET', $path, $query, $headers));
    return ['status' => $r->status(), 'body' => (array) json_decode($r->body(), true)];
}

/** 清掉测试用户的成长数据，保证断言从确定状态开始。 */
function memberPurge(Kernel $k, array $userIds): void
{
    $k->boot();
    $db = $k->container()->resolve(DatabaseInterface::class);
    foreach ($userIds as $id) {
        $db->exec('DELETE FROM ' . $db->prefix() . 'member_user_titles WHERE user_id = :u', [':u' => (string) $id]);
        $db->exec('DELETE FROM ' . $db->prefix() . 'member_xp_events WHERE user_id = :u', [':u' => (string) $id]);
        $db->exec('DELETE FROM ' . $db->prefix() . 'member_profiles WHERE user_id = :u', [':u' => (string) $id]);
    }
}

check('站长定义头衔：标识 / 名称 / 颜色 / 权重 / 门槛全部自定义', function (): void {
    $k = memberKernel();
    $key = 't16b-' . bin2hex(random_bytes(3));
    try {
        $r = memberPost($k, '/api/v1/member/title', [
            'title_key' => $key, 'name' => '测试徽章', 'description' => '站长自定义',
            'color' => '#123456', 'priority' => 77, 'visible' => 1, 'min_level' => 0,
        ], adminHeaders());
        truthy(($r['body']['code'] ?? null) === 0, '定义头衔失败');
        $list = memberGet($k, '/api/v1/member/titles', ['include_hidden' => '1']);
        $row = null;
        foreach ((array) ($list['body']['data']['titles'] ?? []) as $item) {
            if (($item['title_key'] ?? '') === $key) $row = $item;
        }
        truthy($row !== null, '自定义头衔未出现在目录');
        truthy(($row['name'] ?? '') === '测试徽章' && ($row['color'] ?? '') === '#123456', '头衔字段未持久化');
        truthy((int) ($row['priority'] ?? 0) === 77, '头衔权重未持久化');
    } finally {
        memberPost($k, '/api/v1/member/title', ['op' => 'delete', 'title_key' => $key], adminHeaders());
    }
});

check('手动授予 / 撤销头衔：档案如实回显，来源标记为 manual', function (): void {
    $k = memberKernel();
    $uid = 9201;
    memberPurge($k, [$uid]);
    try {
        // 先记一笔经验建档案：v0.41 起没有档案也没有账号的 ID 查不到（40403），
        // 而头衔关系本身不建档案 —— 这正是「授予头衔不写成长数据」的证据。
        memberPost($k, '/api/v1/member/xp/award', [
            'user_id' => (string) $uid, 'action' => 'manual.adjust',
            'event_key' => 't16b-grant-' . $uid, 'amount' => 1,
        ], adminHeaders());
        memberPost($k, '/api/v1/member/title/grant', ['user_id' => (string) $uid, 'title_key' => 'founder'], adminHeaders());
        $profile = memberGet($k, '/api/v1/member/profile', ['user_id' => (string) $uid])['body']['data'] ?? [];
        $keys = array_column((array) ($profile['titles'] ?? []), 'title_key');
        truthy(in_array('founder', $keys, true), '授予后档案未显示头衔');
        $founder = null;
        foreach ((array) $profile['titles'] as $t) if ($t['title_key'] === 'founder') $founder = $t;
        truthy(($founder['source'] ?? '') === 'manual', '来源应为 manual');

        memberPost($k, '/api/v1/member/title/grant', ['op' => 'revoke', 'user_id' => (string) $uid, 'title_key' => 'founder'], adminHeaders());
        $after = memberGet($k, '/api/v1/member/profile', ['user_id' => (string) $uid])['body']['data'] ?? [];
        truthy(!in_array('founder', array_column((array) ($after['titles'] ?? []), 'title_key'), true), '撤销后仍显示头衔');
    } finally {
        memberPurge($k, [$uid]);
    }
});

check('等级自动头衔：达到 min_level 自动出现，来源标记为 level', function (): void {
    $k = memberKernel();
    $uid = 9202;
    memberPurge($k, [$uid]);
    try {
        // v0.41 起「查无此人」返回 40403，不再编造空档案；没有档案时先记账一笔 1 经验再看头衔。
        $missing = memberGet($k, '/api/v1/member/profile', ['user_id' => (string) $uid]);
        truthy(($missing['body']['code'] ?? 0) === 40403, '无档案的陌生 ID 应返回 40403');
        memberPost($k, '/api/v1/member/xp/award', [
            'user_id' => (string) $uid, 'action' => 'manual.adjust',
            'event_key' => 't16b-low-' . $uid, 'amount' => 1,
        ], adminHeaders());
        $low = memberGet($k, '/api/v1/member/profile', ['user_id' => (string) $uid])['body']['data'] ?? [];
        truthy(!in_array('veteran', array_column((array) ($low['titles'] ?? []), 'title_key'), true), '1 级不该有 4 级头衔');

        $award = memberPost($k, '/api/v1/member/xp/award', [
            'user_id' => (string) $uid, 'action' => 'manual.adjust', 'event_key' => 't16b-auto-' . $uid,
            'amount' => 2500, 'note' => '直接把等级推到 4',
        ], adminHeaders());
        truthy(($award['body']['data']['level'] ?? 0) === 4, '经验未推进到 4 级');

        $high = memberGet($k, '/api/v1/member/profile', ['user_id' => (string) $uid])['body']['data'] ?? [];
        $veteran = null;
        foreach ((array) ($high['titles'] ?? []) as $t) if (($t['title_key'] ?? '') === 'veteran') $veteran = $t;
        truthy($veteran !== null, '达到门槛后未自动获得头衔');
        truthy(($veteran['source'] ?? '') === 'level', '自动头衔来源应为 level');
    } finally {
        memberPurge($k, [$uid]);
    }
});

check('授予头衔不改变任何权限决策：角色集合与 decide 结果前后一致', function (): void {
    $k = memberKernel();
    $uid = 2;
    $k->boot();
    $acl = $k->container()->resolve(AccessControlInterface::class);
    $identity = ['role' => 'subscriber', 'username' => 'sub', 'user_id' => $uid];
    memberPurge($k, [$uid]);
    try {
        $before = $acl->decide($identity, 'content.delete', ['type' => 'post', 'id' => 'first-post']);
        $beforeCreate = $acl->allows($identity, 'content.create');
        truthy($before !== AccessControlInterface::ALLOW, 'subscriber 本不应能删除文章');

        memberPost($k, '/api/v1/member/title/grant', ['user_id' => (string) $uid, 'title_key' => 'founder'], adminHeaders());

        truthy($acl->decide($identity, 'content.delete', ['type' => 'post', 'id' => 'first-post']) === $before,
            '授予头衔后权限决策发生了变化');
        truthy($acl->allows($identity, 'content.create') === $beforeCreate, '授予头衔后角色许可发生了变化');
        $roles = $acl instanceof \Wx\Systems\Permission\AccessController ? $acl->groups()->rolesFor($identity) : [];
        truthy(in_array('subscriber', $roles, true) && count($roles) === 1, '头衔不应扩充角色集合');
    } finally {
        memberPurge($k, [$uid]);
    }
});

check('越权与非法定义：非管理员不能授予头衔，未知头衔被拒', function (): void {
    $k = memberKernel();
    $uid = 9203;
    memberPurge($k, [$uid]);
    try {
        $denied = memberPost($k, '/api/v1/member/title/grant', [
            'user_id' => (string) $uid, 'title_key' => 'founder',
        ], bearer('t-member-subscriber'));
        truthy(($denied['body']['code'] ?? null) === 40301, 'subscriber 越权授予未被拒绝（应为 40301 角色不足）');

        $unknown = memberPost($k, '/api/v1/member/title/grant', [
            'user_id' => (string) $uid, 'title_key' => 'no-such-title',
        ], adminHeaders());
        truthy(($unknown['body']['code'] ?? null) === 42201, '未知头衔未返回 42201');
    } finally {
        memberPurge($k, [$uid]);
    }
});
