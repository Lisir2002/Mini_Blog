<?php
declare(strict_types=1);

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Http\Request;

/**
 * 62 · 评论删除运行时：队列之外也能删，且「删了」与「本来就没了」分开说
 *
 * 从 HTTP 面证明七件事：
 *  1. 队列口径只收 pending / 被举报，删普通评论必须走 content.posts → comments.list；
 *  2. 删除要求手写 id 确认，缺确认 / 未知 op 都是 422 且不跳转；
 *  3. 软删终态、幂等 already、回复链保留都在回显里说清，不塌成「已保存」；
 *  4. 已删除评论再审核被 40901 挡住（状态机终态）；
 *  5. 文章读不到时 fail-closed，不把「不知道」渲染成「没有评论」；
 *  6. 删除动作现场问 comment.delete.any，能审核不等于能删；
 *  7. 成功 303 / 失败不跳转且回填，方法边界成立。
 */
echo "\n=== 62. 评论删除与按文章浏览 ===\n";

$site   = tempSite('queue-runtime');
$prefix = 'q62_';
dropTables($prefix);
$k = isolatedKernel($site, $prefix, [
    'theme' => ['active' => 'glass'],
    'permission' => ['cache' => ['driver' => 'memory']],
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
$admin = ['user_id' => 1, 'username' => 'admin', 'role' => 'admin'];
foreach ([['q62-a', '甲'], ['q62-b', '乙']] as [$slug, $title]) {
    $registry->execute('content.create', ['slug' => $slug, 'title' => $title,
        'body' => '正文', 'status' => 'published', 'author' => 'admin']);
}
$c1 = $registry->execute('comments.add', ['post_slug' => 'q62-a', 'body' => '被举报的一条',
    'author' => '路人', '_identity' => $admin]);
$c2 = $registry->execute('comments.add', ['post_slug' => 'q62-a', 'body' => '普通一条',
    'author' => '路人', '_identity' => $admin]);
$registry->execute('comments.add', ['post_slug' => 'q62-b', 'body' => '另一篇的评论',
    'author' => '路人', '_identity' => $admin]);
$registry->execute('comments.report', ['id' => (int) $c1['id'], 'reason' => 'spam',
    'visitor_id' => 'q62-reporter', '_identity' => $admin]);

[, , $h] = $hit('POST', '/login', [], ['username' => 'admin', 'password' => 'admin123']);
$cookie = 'wx_session=' . cookieValue((string) ($h['Set-Cookie'] ?? ''), 'wx_session');
/** @return array{0:int, 1:string, 2:string} status / Location / body */
$post = static function (array $body, array $q, string $ticket) use ($hit, $cookie): array {
    [$s, $b, $headers] = $hit('POST', '/console/queue', $q,
        $body + ['_form_ticket' => $ticket], ['Cookie' => $cookie]);
    return [$s, (string) ($headers['Location'] ?? ''), $b];
};
$ticketOf = static function (array $q) use ($hit, $cookie, $field): string {
    [, $page] = $hit('GET', '/console/queue', $q, [], ['Cookie' => $cookie]);
    return $field($page, '_form_ticket');
};
$follow = static function (string $loc) use ($hit, $cookie): array {
    $q = []; parse_str((string) parse_url($loc, PHP_URL_QUERY), $q);
    [$s, $b] = $hit('GET', '/console/queue', $q, [], ['Cookie' => $cookie]);
    return [$s, $b];
};
$ticket = $ticketOf(['view' => 'queue']);
truthy($ticket !== '', '没拿到表单票据，后面所有写用例都会误判');
$id1 = (int) $c1['id'];
$id2 = (int) $c2['id'];

check('匿名 401；默认页是审核队列 + 文章窗口 + 两个视图，不是含糊空态',
    function () use ($hit, $cookie): void {
        [$anon] = $hit('GET', '/console/queue');
        truthy($anon === 401, '匿名应 401，实际 ' . $anon);
        [$s, $page] = $hit('GET', '/console/queue', [], [], ['Cookie' => $cookie]);
        truthy($s === 200, '管理员应 200，实际 ' . $s);
        foreach (['审核队列', '选中一篇文章', '按文章浏览评论', '最多 20 篇'] as $needle) {
            truthy(str_contains($page, $needle), '缺少「' . $needle . '」');
        }
    });

check('队列条目同时给状态迁移与删除表单，删除要手写 id 且讲清终态与权限',
    function () use ($hit, $cookie, $id1): void {
        [, $page] = $hit('GET', '/console/queue', ['view' => 'queue'], [], ['Cookie' => $cookie]);
        foreach (['name="to" value="published"', 'name="op" value="delete"',
            'name="confirm"', '终态', 'comment.delete.any'] as $needle) {
            truthy(str_contains($page, $needle), '缺少「' . $needle . '」');
        }
        truthy(str_contains($page, '#' . $id1), '没列出被举报的评论');
    });

check('删除缺确认 422 且不跳转，失败回填刚填错的 confirm；未知 op 同样 422',
    function () use ($post, $ticket, $id1): void {
        [$s, $loc, $b] = $post(['op' => 'delete', 'id' => (string) $id1, 'confirm' => 'wrong',
            'view' => 'queue'], ['view' => 'queue'], $ticket);
        truthy($s === 422 && $loc === '', '缺确认不该跳转，实际 ' . $s);
        truthy(str_contains($b, '手写评论 id'), '没说明确认要求');
        truthy(str_contains($b, 'value="wrong"'), '失败没回填刚填错的值');
        [$s2, $loc2, $b2] = $post(['op' => 'bogus', 'id' => (string) $id1], [], $ticket);
        truthy($s2 === 422 && $loc2 === '' && str_contains($b2, '未知操作'), '未知 op 没被挡住');
    });

check('软删成功 303，回显「已软删」并说明回复链保留、终态不可恢复',
    function () use ($post, $ticket, $follow, $id1, $registry): void {
        [$s, $loc] = $post(['op' => 'delete', 'id' => (string) $id1, 'confirm' => (string) $id1,
            'view' => 'queue'], ['view' => 'queue'], $ticket);
        truthy($s === 303 && str_contains($loc, 'outcome=deleted'), '成功应 303 带 deleted，实际 ' . $s);
        [$s2, $page] = $follow($loc);
        truthy($s2 === 200, 'PRG 后 GET 应 200');
        foreach (['已软删', '回复链', '不能恢复'] as $needle) {
            truthy(str_contains($page, $needle), '缺少「' . $needle . '」');
        }
        $list = $registry->execute('comments.list', ['post_slug' => 'q62-a', '_identity' => null]);
        $row = null;
        foreach ($list['items'] as $it) if ((int) $it['id'] === $id1) $row = $it;
        truthy($row !== null && (bool) $row['deleted'] === true, '软删占位没保留回复链');
        truthy((string) ($row['body'] ?? 'x') === '', '正文没清空');
    });

check('重复删除回报「本来就已删除」，接口幂等但页面不谎报成功',
    function () use ($post, $ticketOf, $follow, $id1): void {
        $t = $ticketOf(['view' => 'post', 'slug' => 'q62-a']);
        [$s, $loc] = $post(['op' => 'delete', 'id' => (string) $id1, 'confirm' => (string) $id1,
            'view' => 'post', 'slug' => 'q62-a'], ['view' => 'post', 'slug' => 'q62-a'], $t);
        truthy($s === 303 && str_contains($loc, 'outcome=already'), '应回报 already，实际 ' . $loc);
        [, $page] = $follow($loc);
        truthy(str_contains($page, '本来就已删除'), '幂等文案缺失');
        truthy(str_contains($page, '没有重复写库'), '没说明不再写库');
    });

check('已删除评论再审核被状态机 40901 挡住；终态既不迁入也不迁出',
    function () use ($post, $ticket, $id1): void {
        [$s, $loc, $b] = $post(['to' => 'published', 'id' => (string) $id1,
            'view' => 'queue'], ['view' => 'queue'], $ticket);
        truthy($s !== 303 && $loc === '', '终态不该允许迁移');
        truthy(str_contains($b, '已删除的评论不可再审核'), '没解释 40901 的原因');
    });

check('按文章浏览：当前文章 + 普通评论 + 已删除占位，不误报「没有评论」',
    function () use ($hit, $cookie): void {
        [$s, $page] = $hit('GET', '/console/queue', ['view' => 'post', 'slug' => 'q62-a'],
            [], ['Cookie' => $cookie]);
        truthy($s === 200, '文章视图应 200');
        foreach (['当前文章：q62-a', '普通一条', '已删除占位', 'name="op" value="delete"'] as $needle) {
            truthy(str_contains($page, $needle), '缺少「' . $needle . '」');
        }
        truthy(!str_contains($page, '这篇文章确实没有评论'), '把有评论的文章说成空态');
        truthy(str_contains($page, 'name="slug" value="q62-a"'), '删除表单没保留文章上下文');
    });

check('文章读不到时 fail-closed：说「读不到」并关掉写入口，不写孤儿',
    function () use ($hit, $cookie, $post, $ticket): void {
        [$s, $page] = $hit('GET', '/console/queue', ['view' => 'post', 'slug' => 'ghost-post'],
            [], ['Cookie' => $cookie]);
        truthy($s === 200, '读不到文章的页仍应呈现说明，实际 ' . $s);
        truthy(str_contains($page, '这篇文章读不到'), '没说「读不到」');
        truthy(!str_contains($page, '这篇文章确实没有评论'), '把「不知道」写成「没有评论」');
        truthy(!str_contains($page, 'name="op" value="delete"'), '文章读不到还给删除入口');
    });

check('权限隔离：只留 comment.moderate 时队列仍可读，删除提交 403 且点明权限',
    function () use ($k, $post, $ticket, $hit, $cookie, $id2): void {
        $acl = $k->container()->resolve(AccessControlInterface::class);
        // 票据绑 comment.moderate（页面级），验票不重查 ACL；403 必须由 Action 现场裁决给出。
        $acl->revoke('admin', '*');
        $acl->grant('admin', 'comment.moderate');
        try {
            [$s, $loc, $b] = $post(['op' => 'delete', 'id' => (string) $id2,
                'confirm' => (string) $id2, 'view' => 'queue'], ['view' => 'queue'], $ticket);
            truthy($s === 403 && $loc === '', '越权删除没被挡住，实际 ' . $s);
            truthy(str_contains($b, 'comment.delete.any'), '没点明缺哪个权限点');
            [, $view] = $hit('GET', '/console/queue', ['view' => 'queue'], [], ['Cookie' => $cookie]);
            truthy(str_contains($view, '审核队列'), '只有审核权限时连队列都看不到了');
        } finally {
            $acl->revoke('admin', 'comment.moderate');
            $acl->grant('admin', '*');
        }
    });

check('方法边界：无票据 Cookie POST 401，已认证 PUT 405；失败不跳转',
    function () use ($hit, $cookie, $post, $ticket, $id2): void {
        [$anon] = $hit('POST', '/console/queue', [], [], ['Cookie' => $cookie]);
        truthy($anon === 401, '无票据 POST 应 401，实际 ' . $anon);
        [$put, , $heads] = $hit('PUT', '/console/queue', [], [], bearer('test-admin-token'));
        truthy($put === 405 && ($heads['Allow'] ?? '') === 'GET, HEAD, POST', 'PUT 的 405/Allow 不对');
        [$s, $loc, $b] = $post(['op' => 'delete', 'id' => (string) $id2, 'confirm' => '',
            'view' => 'queue'], ['view' => 'queue'], $ticket);
        truthy($s === 422 && $loc === '', '失败不该跳转');
        truthy(str_contains($b, '提交没有生效'), '没有渲染失败提示条');
    });
