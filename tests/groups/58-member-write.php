<?php
declare(strict_types=1);

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Http\Request;

/**
 * 58 · 成员写操作运行时：能看 ≠ 能改，改完要如实回报
 *
 * 从 HTTP 面证明六件事：
 *  1. 五个动作各有自己的写权限点，票据按 member.read 签发，不能替代裁决；
 *  2. 成功 303 / 失败绝不 303 且表单回填；
 *  3. 发经验的五种 outcome 分开说：真加分 / 幂等重放 / 上限截断 / 规则停用 / 加 0；
 *  4. 没有规则又不填 amount 时给出「要么定规则、要么填 amount」的可执行提示；
 *  5. 挡住接口挡不住的两类脏数据：授予目录外的头衔、删除不写确认；
 *  6. 结果文案不把「没事发生」说成「操作成功」。
 */
echo "\n=== 58. 成员写操作运行时 ===\n";

$site   = tempSite('member-write');
$prefix = 'mw_';
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
$field = static function (string $html, string $name): string {
    return preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html, $m)
        ? (string) $m[1] : '';
};
$registry = $k->container()->resolve(ApiRegistryInterface::class);

[, , $h] = $hit('POST', '/login', [], ['username' => 'admin', 'password' => 'admin123']);
$cookie = 'wx_session=' . cookieValue((string) ($h['Set-Cookie'] ?? ''), 'wx_session');
[, $page] = $hit('GET', '/console/member', [], [], ['Cookie' => $cookie]);
$ticket = $field($page, '_form_ticket');
truthy($ticket !== '', '没拿到表单票据，后面所有写用例都会误判');

/** @return array{0:int, 1:string, 2:string} status / Location / body */
$post = static function (array $body, array $q = []) use ($hit, $cookie, $ticket): array {
    [$s, $b, $headers] = $hit('POST', '/console/member', $q,
        $body + ['_form_ticket' => $ticket], ['Cookie' => $cookie]);
    return [$s, (string) ($headers['Location'] ?? ''), $b];
};

check('操作台：五个面板都在，默认只渲染发放经验这一张表', function () use ($page, $hit, $cookie): void {
    foreach (['发放经验', '经验规则', '头衔定义', '授予头衔', '等级定义'] as $label) {
        truthy(str_contains($page, $label), '面板切换里缺少 ' . $label);
    }
    truthy(str_contains($page, 'mf-award-event_key'), '默认没有渲染 event_key 字段');
    truthy(!str_contains($page, 'mf-level_def-min_xp'), '默认面板不该混进等级字段');
    foreach (['xp_rule', 'title_def', 'title_grant', 'level_def'] as $panel) {
        [, $other] = $hit('GET', '/console/member', ['panel' => $panel], [], ['Cookie' => $cookie]);
        truthy(str_contains($other, '操作台 · 选择要做的改动'), $panel . ' 面板没有渲染');
    }
});

check('无票据 POST 401；已认证的 PUT 405；Bearer 空提交 422', function () use ($hit, $cookie): void {
    [$byCookie] = $hit('POST', '/console/member', [], [], ['Cookie' => $cookie]);
    truthy($byCookie === 401, 'Cookie POST 应 401，实际 ' . $byCookie);
    [$byToken, , $heads] = $hit('POST', '/console/member', [], [], bearer('test-admin-token'));
    truthy($byToken === 422, 'Bearer 空提交应 422，实际 ' . $byToken);
    // 非 GET 且无票据一律 401（守卫层），405 只对已认证请求成立。
    [$put, , $ph] = $hit('PUT', '/console/member', [], [], bearer('test-admin-token'));
    truthy($put === 405 && ($ph['Allow'] ?? '') === 'GET, HEAD, POST', 'PUT 的 405/Allow 不对');
});

check('op 只能由按钮给出：直接构造的空 op 一律 422', function () use ($post): void {
    [$s, $loc, $b] = $post(['action' => 'award', 'user_id' => '1', 'xp_action' => 'post.publish']);
    truthy($s === 422 && $loc === '', '缺 op 不该成功或跳转');
    truthy(str_contains($b, '请通过表单按钮提交'), '没有说明 op 必须由按钮给出');
});

check('发经验：显式 amount 成功 303，回报实际加分与当前等级', function () use ($post, $registry): void {
    [$s, $loc] = $post(['action' => 'award', 'op' => 'award', 'user_id' => '1',
        'xp_action' => 'manual.adjust', 'event_key' => 't58-award-1', 'amount' => '30']);
    truthy($s === 303, '发经验应 303，实际 ' . $s);
    truthy(str_contains($loc, 'saved=award') && str_contains($loc, 'outcome=applied'),
        '303 没带 outcome：' . $loc);
    truthy(str_contains(urldecode($loc), '加了 30 分'), '没有回报实际加了多少');
    $profile = $registry->execute('member.profile', ['user_id' => '1']);
    truthy((int) ($profile['xp'] ?? 0) === 30, '页面说加了 30，档案里不是 30');
});

check('同一个 event_key 重放：说「没有重复加分」，不假装又发了一笔', function () use ($post, $registry): void {
    [$s, $loc] = $post(['action' => 'award', 'op' => 'award', 'user_id' => '1',
        'xp_action' => 'manual.adjust', 'event_key' => 't58-award-1', 'amount' => '30']);
    truthy($s === 303 && str_contains($loc, 'outcome=duplicate'), '重放没标 duplicate：' . $loc);
    truthy(str_contains(urldecode($loc), '没有重复加分'), '文案没说明是幂等在生效');
    truthy((int) ($registry->execute('member.profile', ['user_id' => '1'])['xp'] ?? 0) === 30,
        '重放竟然又加了分');
});

check('没规则又不填 amount：422 + 可执行提示 + 表单回填，绝不 303', function () use ($post): void {
    [$s, $loc, $b] = $post(['action' => 'award', 'op' => 'award', 'user_id' => '1',
        'xp_action' => 't58.no.rule', 'event_key' => 't58-norule']);
    truthy($s === 422 && $loc === '', '失败不该跳转');
    truthy(str_contains($b, '要么'), '没有给出「定规则 / 填 amount」两条路');
    truthy(str_contains($b, 't58.no.rule'), '表单没有回填刚填的动作名');
    truthy(str_contains($b, '提交没有生效'), '没有渲染失败提示条');
});

check('每日上限截断：说「实际只加了 N 分」，不是一句已发放', function () use ($post, $registry): void {
    $registry->execute('member.xp.rule', ['action' => 'post.publish', 'amount' => 10,
        'daily_cap' => 3, 'enabled' => 1, 'description' => '守卫造数']);
    try {
        [$s, $loc] = $post(['action' => 'award', 'op' => 'award', 'user_id' => '1',
            'xp_action' => 'post.publish', 'event_key' => 't58-cap-1', 'amount' => '10']);
        truthy($s === 303 && str_contains($loc, 'outcome=truncated'), '没标截断：' . $loc);
        truthy(str_contains(urldecode($loc), '实际只加了 3 分'), '没有说清被截断后的实际值');
    } finally {
        $registry->execute('member.xp.rule', ['action' => 'post.publish', 'amount' => 50,
            'daily_cap' => 200, 'enabled' => 1, 'description' => '发布文章']);
    }
});

check('规则停用：本次加 0 分，文案与「成功」分开', function () use ($post, $registry): void {
    $registry->execute('member.xp.rule', ['action' => 'comment.create', 'amount' => 5,
        'daily_cap' => 50, 'enabled' => 0, 'description' => '停用中']);
    try {
        [, $loc] = $post(['action' => 'award', 'op' => 'award', 'user_id' => '1',
            'xp_action' => 'comment.create', 'event_key' => 't58-off-1']);
        truthy(str_contains($loc, 'outcome=disabled'), '没标停用：' . $loc);
        truthy(str_contains(urldecode($loc), '本次加 0 分'), '没有明说加了 0 分');
    } finally {
        $registry->execute('member.xp.rule', ['action' => 'comment.create', 'amount' => 5,
            'daily_cap' => 50, 'enabled' => 1, 'description' => '发表评论']);
    }
});

check('授予目录外的头衔：页面硬挡，不写一条看不见的孤儿关系', function () use ($post): void {
    [$s, $loc, $b] = $post(['action' => 'title_grant', 'op' => 'grant',
        'user_id' => '1', 'title_key' => 't58-no-such-title']);
    truthy($s === 422 && $loc === '', '不该放行');
    truthy(str_contains($b, '不在目录里'), '没有解释「不报错但永远看不到」');
});

check('删除定义不写确认：422；写了确认才删得掉', function () use ($post, $registry): void {
    $registry->execute('member.title', ['title_key' => 't58-badge', 'name' => '测试徽章']);
    try {
        [$s, $loc, $b] = $post(['action' => 'title_def', 'op' => 'delete', 'title_key' => 't58-badge']);
        truthy($s === 422 && $loc === '', '没确认就删掉了');
        truthy(str_contains($b, '原样手写'), '没有说明确认方式');
        [$ok, $loc2] = $post(['action' => 'title_def', 'op' => 'delete',
            'title_key' => 't58-badge', 'confirm' => 't58-badge']);
        truthy($ok === 303 && str_contains($loc2, 'changed=1'), '确认后没删掉：' . $loc2);
        $keys = array_column((array) ($registry->execute('member.titles',
            ['include_hidden' => true])['titles'] ?? []), 'title_key');
        truthy(!in_array('t58-badge', $keys, true), '目录里还有这个头衔');
    } finally {
        $registry->execute('member.title', ['op' => 'delete', 'title_key' => 't58-badge']);
    }
});

check('changed=false 分场景：本来就有、本来就没有、删除不存在的定义', function () use ($post, $registry): void {
    $registry->execute('member.title', ['title_key' => 't58-once', 'name' => '一次性徽章']);
    try {
        [, $loc1] = $post(['action' => 'title_grant', 'op' => 'grant',
            'user_id' => '1', 'title_key' => 't58-once']);
        truthy(str_contains($loc1, 'changed=1'), '首次授予应 changed=1');
        [, $loc2] = $post(['action' => 'title_grant', 'op' => 'grant',
            'user_id' => '1', 'title_key' => 't58-once']);
        truthy(str_contains($loc2, 'outcome=no-change'), '重复授予没标 no-change');
        truthy(str_contains(urldecode($loc2), '本来就已经授予'), '没说清是重复授予');
        [, $loc3] = $post(['action' => 'title_def', 'op' => 'delete',
            'title_key' => 't58-nope', 'confirm' => 't58-nope']);
        truthy(str_contains(urldecode($loc3), '本来就不存在'), '删不存在的定义没有单独说法');
    } finally {
        $registry->execute('member.title', ['op' => 'delete', 'title_key' => 't58-once']);
    }
});

check('给既无账号也无档案的 ID 发经验：不拦，但明说会凭空建档案', function () use ($post): void {
    [, $loc] = $post(['action' => 'award', 'op' => 'award', 'user_id' => '9588',
        'xp_action' => 'manual.adjust', 'event_key' => 't58-orphan', 'amount' => '1']);
    truthy(str_contains(urldecode($loc), '凭空建一份没人能认领的档案'), '没有提示档案是凭空建的');
});

check('权限隔离：只有 member.xp.award 时能发经验，改规则仍 403（票据不替代裁决）',
    function () use ($k, $post, $hit, $cookie): void {
        $acl = $k->container()->resolve(AccessControlInterface::class);
        $acl->revoke('admin', '*');
        $acl->grant('admin', 'member.read');
        $acl->grant('admin', 'member.xp.award');
        try {
            [, $view] = $hit('GET', '/console/member', ['panel' => 'xp_rule'], [], ['Cookie' => $cookie]);
            truthy(str_contains($view, '没有「经验规则」的权限'), '没权限时仍渲染了写入口');
            [$s, $loc, $b] = $post(['action' => 'xp_rule', 'op' => 'upsert',
                'rule_action' => 't58.sneaky', 'amount' => '5']);
            truthy($s === 403 && $loc === '', '越权提交没被挡住，实际 ' . $s);
            truthy(str_contains($b, 'member.xp.rule.manage'), '没有点明缺哪个权限点');
        } finally {
            $acl->revoke('admin', 'member.xp.award');
            $acl->revoke('admin', 'member.read');
            $acl->grant('admin', '*');
        }
    });
