<?php
declare(strict_types=1);

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Http\Request;

/**
 * 57 · 成员成长页运行时：只读、逐块降级、口径分开
 *
 * 从 HTTP 面证明五件事：
 *  1. 排行榜的 total 是有档案的成员总数，returned 才是本页条数（本版修掉的语义）；
 *  2. 「查无此人」返回 40403，不拿一份 0 经验 / Lv.1 的空档案冒充用户；
 *  3. 头衔目录在后台必须显式 include_hidden —— 否则看不见自己建的隐藏头衔；
 *  4. 「空榜 / 没权限看规则 / 等级表断点」三种状态各有各的说法，不塌成一句「没有」；
 *  5. 只读：Cookie POST 401、Bearer POST 405；成员层停用时页面仍 200 但逐块降级。
 */
echo "\n=== 57. 成员成长页运行时 ===\n";

$site   = tempSite('member-runtime');
$prefix = 'mm_';
dropTables($prefix);
$k = isolatedKernel($site, $prefix, [
    'theme' => ['active' => 'glass'],
    'permission' => ['cache' => ['driver' => 'memory']],
]);
$k->boot();

/** @return array{0:int, 1:string, 2:array<string,string>} */
$hit = static function (string $method, string $path, array $q = [], array $body = [],
    array $headers = []) use ($k): array {
    $r = $k->handle(new Request($method, $path, $q, $headers, $body));
    return [$r->status(), $r->body(), $r->headers()];
};
$registry = $k->container()->resolve(ApiRegistryInterface::class);

[$anon] = $hit('GET', '/console/member');
check('匿名访问：401', function () use ($anon): void {
    truthy($anon === 401, '匿名应 401，实际 ' . $anon);
});

[, , $h] = $hit('POST', '/login', [], ['username' => 'admin', 'password' => 'admin123']);
$cookie = 'wx_session=' . cookieValue((string) ($h['Set-Cookie'] ?? ''), 'wx_session');

[$status, $page] = $hit('GET', '/console/member', [], [], ['Cookie' => $cookie]);
check('管理员 GET：五块都渲染，空榜说清「还没有人获得经验」', function () use ($status, $page): void {
    truthy($status === 200, '管理员应 200，实际 ' . $status);
    foreach (['成长数据的只读视图', '等级表 · 5 档', '经验规则 · 3 条', '还没有人获得经验', '账号在 /console/users'] as $needle) {
        truthy(str_contains($page, $needle), '页面缺少「' . $needle . '」');
    }
    truthy(!str_contains($page, '共 0 位有档案的成员'), '空榜不该渲染成一份 0 人的排行表');
});

check('接口语义（空）：total / returned / has_more / next_offset 各就各位', function () use ($registry): void {
    $board = $registry->execute('member.leaderboard', ['limit' => 2]);
    truthy((int) ($board['total'] ?? -1) === 0, '空榜 total 应为 0');
    truthy((int) ($board['returned'] ?? -1) === 0, '空榜 returned 应为 0');
    truthy(($board['has_more'] ?? true) === false, '空榜不该说还有下一页');
    truthy(array_key_exists('next_offset', $board) && $board['next_offset'] === null,
        '空榜 next_offset 应为 null');
});

foreach ([['9401', 300], ['9402', 200], ['9403', 100]] as [$uid, $amount]) {
    $registry->execute('member.xp.award', ['user_id' => $uid, 'action' => 'manual.adjust',
        'event_key' => 't57-' . $uid, 'amount' => $amount, 'note' => '守卫造数']);
}

check('接口语义：total 是总人数、returned 是本页、has_more 决定还有没有下一页', function () use ($registry): void {
    $first = $registry->execute('member.leaderboard', ['limit' => 2]);
    truthy((int) ($first['total'] ?? -1) === 3, 'total 应为 3 位有档案的成员，实际 ' . ($first['total'] ?? 'null'));
    truthy((int) ($first['returned'] ?? -1) === 2, 'returned 应为 2');
    truthy(count((array) ($first['items'] ?? [])) === 2, '本页应为 2 条');
    truthy(($first['has_more'] ?? false) === true, '3 人取 2 条应标记 has_more');
    truthy((int) ($first['next_offset'] ?? -1) === 2, 'next_offset 应为 2');
    truthy((int) ($first['items'][0]['rank'] ?? 0) === 1 && (int) ($first['items'][1]['rank'] ?? 0) === 2,
        '本页名次应为 1、2');
    truthy((int) ($first['items'][0]['xp'] ?? 0) === 300, '排行应按经验降序');

    $second = $registry->execute('member.leaderboard', ['limit' => 2, 'offset' => 2]);
    truthy((int) ($second['items'][0]['rank'] ?? 0) === 3, '第二页首条名次应为 3');
    truthy(($second['has_more'] ?? true) === false, '最后一页不该说还有下一页');
    truthy(array_key_exists('next_offset', $second) && $second['next_offset'] === null,
        '最后一页 next_offset 应为 null');
});

[, $paged] = $hit('GET', '/console/member', ['offset' => '2'], [], ['Cookie' => $cookie]);
check('页面翻页：名次窗口与「共 3 位」同时出现，且声明这是最后一页', function () use ($paged): void {
    truthy(str_contains($paged, '第 3–3 位 · 共 3 位有档案的成员'), '窗口说明不对');
    truthy(str_contains($paged, '这已是最后一页'), '最后一页没有明说');
});

[, $beyond] = $hit('GET', '/console/member', ['offset' => '3'], [], ['Cookie' => $cookie]);
check('翻过末尾：明说「越过末尾」并指回第一页，而不是渲染一张 0 行表', function () use ($beyond): void {
    truthy(str_contains($beyond, '第 4 位之后没有内容'), '没有指出越界');
    truthy(str_contains($beyond, '前面还有 3 位'), '没有给出实际条数');
});

check('参数错：offset 非整数 / 越界都 422，不静默回退成第一页', function () use ($hit, $cookie): void {
    [$bad] = $hit('GET', '/console/member', ['offset' => 'abc'], [], ['Cookie' => $cookie]);
    truthy($bad === 422, 'offset=abc 应 422，实际 ' . $bad);
    [$far, $body] = $hit('GET', '/console/member', ['offset' => '99999'], [], ['Cookie' => $cookie]);
    truthy($far === 422, 'offset 越界应 422，实际 ' . $far);
    truthy(str_contains($body, 'offset 最大'), '越界文案没有说明上限');
});

check('头衔目录：后台显式 include_hidden，隐藏项在页面上但公开接口里没有', function () use ($registry, $hit, $cookie): void {
    $registry->execute('member.title', ['title_key' => 't57-secret', 'name' => '内部徽章',
        'visible' => 0, 'priority' => 5]);
    try {
        $public = $registry->execute('member.titles', []);
        $keys = array_column((array) ($public['titles'] ?? []), 'title_key');
        truthy(!in_array('t57-secret', $keys, true), '公开接口默认不该返回隐藏头衔');

        $visible = $registry->execute('member.titles', ['include_hidden' => true]);
        truthy(in_array('t57-secret', array_column((array) ($visible['titles'] ?? []), 'title_key'), true),
            'include_hidden=1 时目录里仍看不到');

        [, $page] = $hit('GET', '/console/member', [], [], ['Cookie' => $cookie]);
        truthy(str_contains($page, '内部徽章'), '后台页面看不到隐藏头衔');
        truthy(str_contains($page, '1 个头衔是隐藏的'), '没有把隐藏数量说出来');
    } finally {
        $registry->execute('member.title', ['op' => 'delete', 'title_key' => 't57-secret']);
    }
});

check('查无此人：数字 ID 与用户名一样返回 40403，不编造 0 经验档案', function () use ($registry, $hit, $cookie): void {
    foreach (['t57-nobody', '999999'] as $who) {
        $failed = false;
        try {
            $registry->execute('member.profile', ['user_id' => $who]);
        } catch (\Throwable $e) {
            $failed = $e instanceof \Wx\Systems\Api\ApiOperationException && $e->apiCode === 40403;
        }
        truthy($failed, $who . ' 应返回 40403');
        [, $page] = $hit('GET', '/console/member', ['user' => $who], [], ['Cookie' => $cookie]);
        truthy(str_contains($page, '用户不存在'), $who . ' 页面没有说「用户不存在」');
        truthy(!str_contains($page, '档案 · '), $who . ' 页面仍然渲染了一份档案');
    }
});

[, $one] = $hit('GET', '/console/member', ['user' => '1'], [], ['Cookie' => $cookie]);
check('档案：显示名 / 等级 / 进度都来自接口', function () use ($one): void {
    truthy(str_contains($one, '档案 · 站点管理员（1）'), '档案标题应为显示名 + 用户 ID');
    truthy(str_contains($one, '头衔不影响任何权限决策'), '缺少头衔说明行');
    truthy(!str_contains($one, '查不了'), '查询失败的文案不该出现');
});

[, $ghost] = $hit('GET', '/console/member', ['user' => '9401'], [], ['Cookie' => $cookie]);
check('有档案、无账号：照样显示档案，但明说「查不到账号」', function () use ($ghost): void {
    truthy(str_contains($ghost, '档案在，但查不到账号'), '没有声明账号查不到');
    truthy(str_contains($ghost, '300'), '档案未显示经验余额');
    truthy(str_contains($ghost, '发经验不校验账号是否存在'), '没有解释这种档案是怎么来的');
});

$registry->execute('member.level', ['op' => 'delete', 'level' => 2]);
try {
    [, $gap] = $hit('GET', '/console/member', [], [], ['Cookie' => $cookie]);
    check('等级表断点：缺号被明确指出，而不是安静地渲染剩下的四档', function () use ($gap): void {
        truthy(str_contains($gap, '等级表有断点'), '没有提示断点');
        truthy(str_contains($gap, '缺少 Lv.2'), '没有指出缺哪一级');
    });
} finally {
    $registry->execute('member.level', ['level' => 2, 'name' => '常驻读者', 'min_xp' => 100, 'color' => '#4a9eff']);
}

check('看不到规则：说「你没权限」而不是「没有规则」', function () use ($k, $hit, $cookie): void {
    $acl = $k->container()->resolve(AccessControlInterface::class);
    $acl->revoke('admin', '*');
    $acl->grant('admin', 'member.read');
    try {
        [$status, $page] = $hit('GET', '/console/member', [], [], ['Cookie' => $cookie]);
        truthy($status === 200, '只有 member.read 时页面仍应可访问，实际 ' . $status);
        truthy(str_contains($page, '看不到经验规则'), '没有说清是权限问题');
        truthy(str_contains($page, '这不是「没有规则」'), '没有区分「看不到」与「没有」');
        truthy(!str_contains($page, 'post.publish'), '无权限时仍然渲染了规则明细');
    } finally {
        $acl->revoke('admin', 'member.read');
        $acl->grant('admin', '*');
    }
});

check('只读边界：Cookie POST 401，Bearer POST 405 + Allow', function () use ($hit, $cookie): void {
    [$byCookie] = $hit('POST', '/console/member', [], [], ['Cookie' => $cookie]);
    truthy($byCookie === 401, 'Cookie POST 应 401，实际 ' . $byCookie);
    [$byToken, , $heads] = $hit('POST', '/console/member', [], [], bearer('test-admin-token'));
    truthy($byToken === 405, 'Bearer POST 应 405，实际 ' . $byToken);
    truthy(($heads['Allow'] ?? '') === 'GET, HEAD', 'Allow 头不对：' . ($heads['Allow'] ?? '(空)'));
});

check('成员层停用：页面仍 200，但逐块声明「不可用」而不是空白', function () use ($site, $prefix, $cookie): void {
    $off = isolatedKernel($site, $prefix, [
        'theme' => ['active' => 'glass'],
        'systems' => ['disabled' => ['member']],
        'permission' => ['cache' => ['driver' => 'memory']],
    ]);
    $r = $off->handle(new Request('GET', '/console/member', [], ['Cookie' => $cookie]));
    truthy($r->status() === 200, '停用成员层后页面不该整体挂掉，实际 ' . $r->status());
    $body = $r->body();
    truthy(str_contains($body, '等级表不可用'), '没有声明等级表不可用');
    truthy(str_contains($body, 'member.levels'), '没有给出不可用的原因');
});
