<?php
declare(strict_types=1);

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Http\Request;

/**
 * 60 · 资源 ACL 与用户组运行时：先选中资源，再改授权
 *
 * 从 HTTP 面证明七件事：
 *  1. acl.list 无按资源过滤参数，必须先按 content.posts 选文章才能在页面层筛；
 *  2. 主体 / 角色 / 能力 / 账号四类「写错但不报错」的输入被页面层挡住；
 *  3. users.view 作为读路径与写前校验两处被消费；
 *  4. acl.set 是追加：高危「全部主体 + 全部能力」deny 要手写确认，结果明说不能抵消已有 deny；
 *  5. 用户组七个动作、changed=false 分场景、删除确认；
 *  6. 角色树 / 覆盖台账两个只读面板可用，覆盖授权刻意不进网页；
 *  7. 读写权限分离（read 能看、manage 才能写），成功 303 / 失败不跳转。
 */
echo "\n=== 60. 资源 ACL 与用户组运行时 ===\n";

$site   = tempSite('acl-runtime');
$prefix = 'ax_';
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
foreach ([['t60-a', 'ACL 测试甲'], ['t60-risk', 'ACL 高危测试']] as [$slug, $title]) {
    $registry->execute('content.create', ['slug' => $slug, 'title' => $title,
        'body' => '正文', 'status' => 'published', 'author' => 'admin']);
}

[, , $h] = $hit('POST', '/login', [], ['username' => 'admin', 'password' => 'admin123']);
$cookie = 'wx_session=' . cookieValue((string) ($h['Set-Cookie'] ?? ''), 'wx_session');

/** @return array{0:int, 1:string, 2:string} status / Location / body */
$post = static function (array $body, array $q, string $ticket) use ($hit, $cookie): array {
    [$s, $b, $headers] = $hit('POST', '/console/acl', $q,
        $body + ['_form_ticket' => $ticket], ['Cookie' => $cookie]);
    return [$s, (string) ($headers['Location'] ?? ''), $b];
};
$ticketOf = static function (array $q) use ($hit, $cookie, $field): string {
    [, $page] = $hit('GET', '/console/acl', $q, [], ['Cookie' => $cookie]);
    return $field($page, '_form_ticket');
};

check('匿名 401；默认面板给文章清单，不是一张空的 ACL 表', function () use ($hit, $cookie): void {
    [$anon] = $hit('GET', '/console/acl');
    truthy($anon === 401, '匿名应 401，实际 ' . $anon);
    [$s, $page] = $hit('GET', '/console/acl', [], [], ['Cookie' => $cookie]);
    truthy($s === 200, '管理员应 200，实际 ' . $s);
    truthy(str_contains($page, '先选中一篇文章'), '没有文章选择入口');
    // href 经主题统一转义，& 在 HTML 里是 &amp;；断言仍精确覆盖两个查询参数。
    truthy(str_contains($page, 'panel=acl&amp;slug=t60-a'), '文章链接没带 slug');
    truthy(str_contains($page, '先选中一篇文章 · 最多 20 篇'), '没有说明这是窗口');
});

$aclQ = ['panel' => 'acl', 'slug' => 't60-a'];
$ticket = $ticketOf($aclQ);
truthy($ticket !== '', '没拿到表单票据，后面所有写用例都会误判');

check('选中文章：当前资源 + 空态把「100 条上限」说清', function () use ($hit, $cookie, $aclQ): void {
    [, $page] = $hit('GET', '/console/acl', $aclQ, [], ['Cookie' => $cookie]);
    foreach (['当前资源 · post:t60-a', '没有筛出这篇文章的 ACL 条目', '100 条上限',
        '给 t60-a 追加一条 ACL'] as $needle) {
        truthy(str_contains($page, $needle), '页面缺少「' . $needle . '」');
    }
});

check('追加一条字段级 deny：303，列表里出现，且说明「追加不是编辑」',
    function () use ($post, $ticket, $aclQ, $hit, $cookie, $registry): void {
        [$s, $loc] = $post(['panel' => 'acl', 'slug' => 't60-a', 'subject' => 'role:editor',
            'capability' => 'content.read', 'field' => 'body', 'allow' => '0'], $aclQ, $ticket);
        truthy($s === 303 && str_contains($loc, 'outcome=changed'), '没追加成功：' . $loc);
        truthy(str_contains(urldecode($loc), '不会把它抵消'), '没说明 append 语义');
        $entries = $registry->execute('permission.acl.list', [])['entries'] ?? [];
        truthy(count($entries) === 1 && (int) ($entries[0]['allow'] ?? 1) === 0,
            '库里没有这条 deny');
        [, $page] = $hit('GET', '/console/acl', $aclQ, [], ['Cookie' => $cookie]);
        truthy(str_contains($page, 'role:editor'), '页面没显示筛出的条目');
        truthy(str_contains($page, '显式 deny 优先级最高'), '没有说明 deny 优先级');
    });

check('四类「写错但不报错」的输入被页面层挡住',
    function () use ($post, $ticket, $aclQ): void {
        $cases = [
            ['bogus:foo', 'content.read', 'body', '1', '静默解释成角色'],
            ['role:nobody', 'content.read', 'body', '1', '角色「nobody」不存在'],
            ['*', 'content.no-such-perm', '', '1', '不在权限清单里'],
            ['user:999999', 'content.read', '', '1', '查不到'],
            ['*', 'content.read', 'not_a_field', '1', 'field 只能是'],
        ];
        foreach ($cases as [$subject, $cap, $fld, $allow, $needle]) {
            [$s, $loc, $b] = $post(['panel' => 'acl', 'slug' => 't60-a', 'subject' => $subject,
                'capability' => $cap, 'field' => $fld, 'allow' => $allow], $aclQ, $ticket);
            truthy($s === 422 && $loc === '', $subject . '/' . $cap . ' 没被挡住');
            truthy(str_contains($b, $needle), $subject . ' 没有解释原因（缺「' . $needle . '」）');
        }
    });

check('user:1 真实存在：users.view 校验通过，追加成功',
    function () use ($post, $ticket, $aclQ): void {
        [$s, $loc] = $post(['panel' => 'acl', 'slug' => 't60-a', 'subject' => 'user:1',
            'capability' => 'content.read', 'allow' => '1'], $aclQ, $ticket);
        truthy($s === 303, '真实账号被误挡：' . $loc);
    });

check('allow 只能由按钮给出；高危通配符 deny 要手写确认',
    function () use ($post, $ticket, $aclQ, $ticketOf): void {
        [$s, $loc, $b] = $post(['panel' => 'acl', 'slug' => 't60-a', 'subject' => '*',
            'capability' => 'content.read', 'field' => '', 'allow' => ''], $aclQ, $ticket);
        truthy($s === 422 && str_contains($b, '两个按钮给出'), 'allow 缺失没被挡住');

        $riskQ = ['panel' => 'acl', 'slug' => 't60-risk'];
        $riskTicket = $ticketOf($riskQ);
        [$r1, $l1, $b1] = $post(['panel' => 'acl', 'slug' => 't60-risk', 'subject' => '*',
            'capability' => '*', 'field' => '', 'allow' => '0'], $riskQ, $riskTicket);
        truthy($r1 === 422 && $l1 === '', '高危 deny 没要求确认');
        truthy(str_contains($b1, '管理员也可能进不来'), '没有说明自锁后果');
        [$r2, $l2] = $post(['panel' => 'acl', 'slug' => 't60-risk', 'subject' => '*',
            'capability' => '*', 'field' => '', 'allow' => '0', 'confirm' => 't60-risk'],
            $riskQ, $riskTicket);
        truthy($r2 === 303, '确认后没提交成功：' . $l2);
    });

check('角色树 / 覆盖台账两个只读面板可用，覆盖授权刻意不进网页',
    function () use ($hit, $cookie): void {
        [, $tree] = $hit('GET', '/console/acl', ['panel' => 'tree'], [], ['Cookie' => $cookie]);
        truthy(str_contains($tree, '角色继承树 · 4 个角色'), '角色树没渲染');
        truthy(str_contains($tree, '继承是并集'), '没说明继承语义');
        [, $ov] = $hit('GET', '/console/acl', ['panel' => 'overrides'], [], ['Cookie' => $cookie]);
        truthy(str_contains($ov, '覆盖必须先授权'), '没显示 require_grant 状态');
        truthy(str_contains($ov, '为什么这里没有授权按钮'), '没有解释不接写入口');
        truthy(str_contains($ov, 'RequestGuard'), '没点明本机来源边界');
        truthy(!str_contains($ov, 'name="contract"'), '覆盖台账面板不该出现授权表单');
    });

$GQ = ['panel' => 'groups'];
$gticket = $ticketOf($GQ);
truthy($gticket !== '', '用户组面板没拿到票据');

check('查账号：?user=1 走 users.view，显示是谁；查不到说明别写进分组',
    function () use ($hit, $cookie): void {
        [, $page] = $hit('GET', '/console/acl', ['panel' => 'groups', 'user' => '1'],
            [], ['Cookie' => $cookie]);
        truthy(str_contains($page, 'user:1 = admin'), '没显示账号');
        truthy(str_contains($page, '加成员时请写成 user:1'), '没给出可复制的主体写法');
        [, $miss] = $hit('GET', '/console/acl', ['panel' => 'groups', 'user' => '999999'],
            [], ['Cookie' => $cookie]);
        truthy(str_contains($miss, '查不到'), '不存在的账号没有单独说法');
    });

check('用户组：新建 / 加角色 / 加成员真的落库，写错的角色与成员被挡',
    function () use ($post, $gticket, $GQ, $hit, $cookie, $registry): void {
        [$s, $loc] = $post(['panel' => 'groups', 'op' => 'upsert', 'group' => 'team.a',
            'name' => '甲组', 'description' => '守卫造数', 'sort' => '3'], $GQ, $gticket);
        truthy($s === 303, '建组没成功：' . $loc);
        [, $page] = $hit('GET', '/console/acl', $GQ, [], ['Cookie' => $cookie]);
        truthy(str_contains($page, '用户组 · 1 个') && str_contains($page, '甲组'), '组没出现在清单里');

        [$bad, , $b] = $post(['panel' => 'groups', 'op' => 'add_role', 'group' => 'team.a',
            'role' => 'no-such-role'], $GQ, $gticket);
        truthy($bad === 422 && str_contains($b, '不存在'), '不存在的角色没被挡住');
        [$bad2, , $b2] = $post(['panel' => 'groups', 'op' => 'add_member', 'group' => 'team.a',
            'subject' => 'user:999999'], $GQ, $gticket);
        truthy($bad2 === 422 && str_contains($b2, '查不到'), '不存在的成员没被挡住');

        $post(['panel' => 'groups', 'op' => 'add_role', 'group' => 'team.a', 'role' => 'editor'],
            $GQ, $gticket);
        $post(['panel' => 'groups', 'op' => 'add_member', 'group' => 'team.a', 'subject' => 'user:1'],
            $GQ, $gticket);
        $g = $registry->execute('permission.groups', [])['groups']['team.a'] ?? [];
        truthy(in_array('editor', (array) ($g['roles'] ?? []), true), '角色没写进组');
        truthy(count((array) ($g['members'] ?? [])) === 1, '成员没写进组');
    });

check('changed=false 分场景；删除分组要手写确认', function () use ($post, $gticket, $GQ): void {
    [$missing, , $mb] = $post(['panel' => 'groups', 'op' => 'remove_member', 'group' => 'team.a',
        'subject' => 'user:9401'], $GQ, $gticket);
    truthy($missing === 422 && str_contains($mb, '查不到'), '移除不存在的成员没被挡住');

    [, $loc2] = $post(['panel' => 'groups', 'op' => 'add_member', 'group' => 'team.a',
        'subject' => 'user:1'], $GQ, $gticket);
    truthy(str_contains($loc2, 'outcome=no-change'), '重复绑定没标 no-change：' . $loc2);
    truthy(str_contains(urldecode($loc2), '本来就已经绑定'), '没说清是重复绑定');
    [, $loc3] = $post(['panel' => 'groups', 'op' => 'remove_role', 'group' => 'team.a',
        'role' => 'author'], $GQ, $gticket);
    truthy(str_contains($loc3, 'outcome=no-change'), '移除本来就没有的角色没标 no-change');
    truthy(str_contains(urldecode($loc3), '本来就没有这条绑定'), '没说清是哪一种没变化');

    [$s, $l, $b] = $post(['panel' => 'groups', 'op' => 'delete', 'group' => 'team.a'], $GQ, $gticket);
    truthy($s === 422 && $l === '', '没确认就删掉了分组');
    truthy(str_contains($b, '原样手写'), '没说明确认方式');
    [$ok, $l2] = $post(['panel' => 'groups', 'op' => 'delete', 'group' => 'team.a',
        'confirm' => 'team.a'], $GQ, $gticket);
    truthy($ok === 303 && str_contains($l2, 'outcome=changed'), '确认后没删掉：' . $l2);
});

check('权限隔离：只留 permission.read 时清单仍可读，写提交 403',
    function () use ($k, $post, $ticket, $aclQ, $hit, $cookie): void {
        $acl = $k->container()->resolve(AccessControlInterface::class);
        $acl->revoke('admin', '*');
        $acl->grant('admin', 'permission.read');
        try {
            [$s, $loc, $b] = $post(['panel' => 'acl', 'slug' => 't60-a', 'subject' => '*',
                'capability' => 'content.read', 'allow' => '1'], $aclQ, $ticket);
            truthy($s === 403 && $loc === '', '越权提交没被挡住，实际 ' . $s);
            truthy(str_contains($b, 'permission.manage'), '没点明缺哪个权限点');
            [, $view] = $hit('GET', '/console/acl', $aclQ, [], ['Cookie' => $cookie]);
            truthy(str_contains($view, '当前资源'), '只有读权限时连视图都看不到了');
        } finally {
            $acl->revoke('admin', 'permission.read');
            $acl->grant('admin', '*');
        }
    });

check('方法边界：无票据 POST 401，已认证 PUT 405；失败不跳转且回填',
    function () use ($hit, $cookie, $post, $ticket, $aclQ): void {
        [$anon] = $hit('POST', '/console/acl', [], [], ['Cookie' => $cookie]);
        truthy($anon === 401, '无票据 POST 应 401，实际 ' . $anon);
        [$put, , $heads] = $hit('PUT', '/console/acl', [], [], bearer('test-admin-token'));
        truthy($put === 405 && ($heads['Allow'] ?? '') === 'GET, HEAD, POST', 'PUT 的 405/Allow 不对');
        [$s, $loc, $b] = $post(['panel' => 'acl', 'slug' => 't60-a', 'subject' => 'x',
            'capability' => 'content.read', 'allow' => '1'], $aclQ, $ticket);
        truthy($s === 422 && $loc === '', '失败不该跳转');
        truthy(str_contains($b, '提交没有生效'), '没有渲染失败提示条');
        truthy(str_contains($b, 'value="x"'), '表单没有回填刚填的主体');
    });
