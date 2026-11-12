<?php
declare(strict_types=1);

/**
 * 61b · 分类与标签的边界守卫（与 61 组共用同一个隔离站点）
 *
 * run.php 按文件名顺序 require 分组，61-terms-runtime.php 先建好 $k / $hit /
 * $post / $ticket / $cookie / $registry，本组直接沿用，不重复引导内核 ——
 * 权限隔离必须在同一份已落库的数据上做，另起一个内核反而验不出「看得到但改不了」。
 *
 * 五件事：读不到的文章 fail-closed、两个写权限点分别裁决、方法边界与回填、
 * 50101 降级时写入口关闭。
 */
echo "\n=== 61b. 分类与标签：边界与权限 ===\n";

check('文章读不到时 fail-closed：页面说「读不到」，挂载入口关闭且不写孤儿绑定',
    function () use ($post, $ticket, $hit, $cookie, $registry, $admin): void {
        $registry->execute('content.delete', ['slug' => 't61-trash', '_identity' => $admin]);
        $q = ['slug' => 't61-trash'];
        [$s, $page] = $hit('GET', '/console/terms', $q, [], ['Cookie' => $cookie]);
        truthy($s === 200, '读不到文章的页应仍能呈现说明，实际 ' . $s);
        foreach (['读不到', '不写「这篇文章没有术语」', '挂载入口关闭'] as $needle) {
            truthy(str_contains($page, $needle), '缺少「' . $needle . '」');
        }
        [$s2, $loc2, $b2] = $post(['op' => 'attach', 'slug' => 't61-trash',
            'terms' => 'category:tech'], $q, $ticket);
        truthy($s2 === 422 && $loc2 === '', '读不到的文章应拒绝挂载，实际 ' . $s2);
        truthy(str_contains($b2, '孤儿绑定'), '没说明会写成孤儿绑定');
        // 不存在的 slug 走同一分支：页面仍不说「没有术语」。
        [, $ghost] = $hit('GET', '/console/terms', ['slug' => 'ghost-post'], [], ['Cookie' => $cookie]);
        truthy(str_contains($ghost, '读不到'), '不存在的文章也被当成「没有术语」');
    });

check('权限隔离 A：只留 content.read 时术语写入口 403，且点明 content.manage_terms',
    function () use ($k, $post, $ticket, $hit, $cookie): void {
        $acl = $k->container()->resolve(\Wx\Contracts\AccessControlInterface::class);
        $acl->revoke('admin', '*');
        $acl->grant('admin', 'content.read');
        try {
            [$s, $loc, $b] = $post(['op' => 'create_tag', 'term_slug' => 'nope',
                'name' => '越权'], [], $ticket);
            truthy($s === 403 && $loc === '', '越权新建没被挡住，实际 ' . $s);
            truthy(str_contains($b, 'content.manage_terms'), '没点明缺哪个权限点');
            [, $view] = $hit('GET', '/console/terms', [], [], ['Cookie' => $cookie]);
            truthy(str_contains($view, 'category:tech'), '只有读权限时连清单都看不到了');
            truthy(str_contains($view, '术语写入口不可用'), '没把写入口换成权限说明');
        } finally {
            $acl->revoke('admin', 'content.read');
            $acl->grant('admin', '*');
        }
    });

check('权限隔离 B：文章挂载另问 content.edit，与术语管理不是同一个权限点',
    function () use ($k, $post, $ticket): void {
        $acl = $k->container()->resolve(\Wx\Contracts\AccessControlInterface::class);
        $acl->revoke('admin', '*');
        $acl->grant('admin', 'content.read');
        $acl->grant('admin', 'content.manage_terms');
        try {
            [$s, $loc, $b] = $post(['op' => 'attach', 'slug' => 't61-a',
                'terms' => 'category:tech'], ['slug' => 't61-a'], $ticket);
            truthy($s === 403 && $loc === '', '缺 content.edit 的挂载没被挡住，实际 ' . $s);
            truthy(str_contains($b, 'content.edit'), '没点明挂载需要的权限点');
        } finally {
            $acl->revoke('admin', 'content.read');
            $acl->revoke('admin', 'content.manage_terms');
            $acl->grant('admin', '*');
        }
    });

check('方法边界：无票据 Cookie POST 401，已认证 PUT 405；失败不跳转且回填',
    function () use ($hit, $cookie, $post, $ticket, $field): void {
        [$anon] = $hit('POST', '/console/terms', [], [], ['Cookie' => $cookie]);
        truthy($anon === 401, '无票据 POST 应 401，实际 ' . $anon);
        [$put, , $heads] = $hit('PUT', '/console/terms', [], [], bearer('test-admin-token'));
        truthy($put === 405 && ($heads['Allow'] ?? '') === 'GET, HEAD, POST',
            '已认证 PUT 应 405 并带 Allow，实际 ' . $put);
        [$s, $loc, $body] = $post(['op' => 'create_tag', 'term_slug' => 'BAD!',
            'name' => '回填检查'], [], $ticket);
        truthy($s === 422 && $loc === '', '失败不该跳转');
        truthy($field($body, 'term_slug') === 'BAD!', '失败没回填 term_slug');
        truthy($field($body, 'name') === '回填检查', '失败没回填 name');
    });

check('存储层幂等：同值 update 跨秒仍报 updated=false，updated_at 不制造假变化',
    function () use ($registry): void {
        $r = $registry->execute('content.terms.manage', ['action' => 'create',
            'kind' => 'tag', 'slug' => 'idem', 'name' => '幂等', 'description' => '']);
        $id = (int) ($r['id'] ?? 0);
        truthy($id > 0, '造幂等术语失败');
        $registry->execute('content.terms.manage', ['action' => 'update', 'id' => $id,
            'name' => '改名一次', 'description' => '说明']);
        // 等到秒翻转：旧实现无条件刷新 updated_at，跨秒后同值 UPDATE 会被算成「已改变」。
        $second = time();
        while (time() === $second) { usleep(50000); }
        $again = $registry->execute('content.terms.manage', ['action' => 'update', 'id' => $id,
            'name' => '改名一次', 'description' => '说明']);
        truthy(($again['updated'] ?? null) === false,
            '同值提交跨秒又报 updated=true：幂等不该取决于两次请求相隔几秒');
    });

check('术语清单读不到时（50101）写入口关闭，文案区分「功能不可用」与「还没有分类」',
    function () use ($root): void {
        // 不引导第二个内核也能锁住这条语义：页面必须把 50101 单独成态，
        // 且写入口的裁剪条件是清单状态，而不仅仅是有没有票据。
        $src = (string) file_get_contents($root . '/pages/Terms/TermsForms.php');
        truthy(str_contains($src, "['ok', 'empty']") && str_contains($src, '50101'),
            '写入口没有按清单状态裁剪');
        $q = (string) file_get_contents($root . '/pages/Terms/TermsQuery.php');
        truthy(str_contains($q, '50101') && str_contains($q, "'unavailable'"),
            '取数没把 50101 单独成态');
    });
