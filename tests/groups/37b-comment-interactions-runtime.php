<?php
declare(strict_types=1);

use Wx\Core\Http\Request;
use Wx\Systems\Comment\MemoryCommentStore;
use Wx\Systems\Comment\MemoryInteractionStore;

/**
 * 37b · 互动运行时：真实内核 + 真实 MySQL 的端到端行为。
 *
 * 与 37 的分工：37 验纯策略与双实现语义，这里验「接上分发器、鉴权、
 * 公网写边界、钩子装配之后」的整条链路，尤其是那些只有在集成后才显形的错：
 * editable 空头支票、举报阈值自动下架、节流超频、编辑守卫复用。
 */
echo "\n=== 37b. 互动运行时：HTTP 链路 / 阈值 / 编辑 / 节流 ===\n";

$site   = tempSite('interact-rt');
$prefix = 'ir_';
dropTables($prefix);

/** 同一隔离内核：写接口公网边界打开，便于用不同 IP 模拟不同主体。 */
$k = isolatedKernel($site, $prefix, [
    'api' => ['expose_write' => true],
    'comment' => [
        'auto_publish' => true,
        'edit_window_minutes' => 10,
        'report_threshold' => 2,
        'throttle' => ['enabled' => false],
    ],
]);
$k->boot();
$admin = ['Authorization' => 'Bearer test-admin-token'];

/** @return array{0:int, 1:array<string,mixed>} */
$hit = static function (string $method, string $path, array $q = [], array $body = [], array $h = [], ?string $ip = null) use ($k): array {
    $r = $k->handle(new Request($method, $path, $q, $h, [], json_encode($body), $ip));
    $d = json_decode($r->body(), true);
    return [$r->status(), is_array($d) ? $d : ['raw' => $r->body()]];
};
$code = static fn(array $b): int => (int) ($b['code'] ?? -1);

// 前置：一篇已发布文章 + 一条 admin 身份发布的评论
$hit('POST', '/api/v1/content/posts', [], ['title' => '互动', 'body' => '正文', 'slug' => 'itest', 'status' => 'published'], $admin);
[$s, $created] = $hit('POST', '/api/v1/comments', [], ['post_slug' => 'itest', 'body' => '原始正文', 'author' => '甲'], $admin, '10.1.0.1');
$cid = (int) ($created['data']['id'] ?? 0);

check('前置：文章与评论就绪', function () use ($s, $cid): void {
    truthy($s === 200 && $cid > 0, '前置写入失败：status=' . $s . ' id=' . $cid);
});

check('editable 不再是空头支票：作者视角为 true、匿名视角为 false，后端能力一致', function () use ($hit, $cid, $admin, $code): void {
    [$anonS, $anon] = $hit('GET', '/api/v1/comments', ['post_slug' => 'itest']);
    $anonItem = (array) ($anon['data']['items'][0] ?? []);
    [$ownS, $own] = $hit('GET', '/api/v1/comments', ['post_slug' => 'itest'], [], $admin);
    $ownItem = (array) ($own['data']['items'][0] ?? []);
    truthy($anonS === 200 && $ownS === 200, '列表读取失败');
    truthy(($anonItem['editable'] ?? null) === false, '匿名不该看到编辑按钮');
    truthy(($ownItem['editable'] ?? null) === true, '作者应看到编辑按钮');
    truthy(($ownItem['edit_count'] ?? null) === 0, '版本号必须出站，客户端才能做乐观锁');
    truthy(isset($ownItem['likes'], $ownItem['reports'], $ownItem['liked'], $ownItem['reported']),
        '列表缺互动计数字段');

    // 字段为真时后端必须真能编辑 —— 这一条正是旧实现缺失的接口。
    [$editS, $edit] = $hit('POST', '/api/v1/comments/edit', [], [
        'id' => $cid, 'body' => '改过的正文', 'expected_edit_count' => 0,
    ], $admin, '10.1.0.1');
    truthy($editS === 200 && ($edit['data']['edit_count'] ?? 0) === 1,
        'editable=true 但编辑接口不可用，仍是空头支票：' . $editS . ' ' . json_encode($edit, JSON_UNESCAPED_UNICODE));
});

check('编辑：正文复用发表守卫，超长 / 空内容不能从编辑口绕过', function () use ($hit, $cid, $admin, $code): void {
    [$s1] = $hit('POST', '/api/v1/comments/edit', [], [
        'id' => $cid, 'body' => '', 'expected_edit_count' => 1,
    ], $admin, '10.1.0.1');
    truthy($s1 === 422, '空正文应被拒，实际 ' . $s1);
    [$s2] = $hit('POST', '/api/v1/comments/edit', [], [
        'id' => $cid, 'body' => str_repeat('x', 6000), 'expected_edit_count' => 1,
    ], $admin, '10.1.0.1');
    truthy($s2 === 422, '超长正文应被拒，实际 ' . $s2);
    // 被拒的编辑不能推进版本号，否则客户端版本会莫名其妙过期。
    [$s3, $list] = $hit('GET', '/api/v1/comments', ['post_slug' => 'itest'], [], $admin);
    truthy(($list['data']['items'][0]['edit_count'] ?? -1) === 1, '失败编辑推进了版本号');
});

check('编辑：乐观锁冲突返回 40913，且不覆盖已有正文', function () use ($hit, $cid, $admin, $code): void {
    [$s, $b] = $hit('POST', '/api/v1/comments/edit', [], [
        'id' => $cid, 'body' => '用旧版本再改', 'expected_edit_count' => 0,
    ], $admin, '10.1.0.1');
    truthy($s === 409 && $code($b) === 40913, '旧版本应冲突：' . $s . ' ' . json_encode($b, JSON_UNESCAPED_UNICODE));
    [, $list] = $hit('GET', '/api/v1/comments', ['post_slug' => 'itest'], [], $admin);
    truthy(($list['data']['items'][0]['body'] ?? '') === '改过的正文', '冲突编辑覆盖了正文');
});

check('点赞：幂等重放不重复计数，不同主体累加，主体缺失明确 422', function () use ($hit, $cid, $code): void {
    [$s1, $a] = $hit('POST', '/api/v1/comments/like', [], ['id' => $cid, 'visitor_id' => 'v-a'], [], '10.1.0.1');
    [, $b]    = $hit('POST', '/api/v1/comments/like', [], ['id' => $cid, 'visitor_id' => 'v-a'], [], '10.1.0.1');
    [, $c]    = $hit('POST', '/api/v1/comments/like', [], ['id' => $cid, 'visitor_id' => 'v-b'], [], '10.1.0.2');
    truthy($s1 === 200 && ($a['data']['changed'] ?? null) === true, '首次点赞应成功');
    truthy(($b['data']['changed'] ?? null) === false && ($b['data']['likes'] ?? -1) === 1, '重复点赞重复计数了');
    truthy(($c['data']['likes'] ?? -1) === 2, '不同主体未累加');
    [$s4] = $hit('POST', '/api/v1/comments/like', [], ['id' => $cid], [], null);
    truthy($s4 === 422, '主体完全不可识别应明确拒绝，实际 ' . $s4);
});

check('举报：独立主体达阈值自动打回 pending，重复举报不重复触发', function () use ($hit, $cid, $code): void {
    [, $r1] = $hit('POST', '/api/v1/comments/report', [], ['id' => $cid, 'reason' => 'spam', 'visitor_id' => 'v-a'], [], '10.1.0.1');
    [, $r2] = $hit('POST', '/api/v1/comments/report', [], ['id' => $cid, 'reason' => 'abuse', 'visitor_id' => 'v-a'], [], '10.1.0.1');
    truthy(($r1['data']['reports'] ?? -1) === 1 && ($r1['data']['auto_hidden'] ?? null) === false, '首次举报不该下架');
    truthy(($r2['data']['changed'] ?? null) === false && ($r2['data']['reports'] ?? -1) === 1,
        '重复举报换 reason 也不该累加');
    [, $r3] = $hit('POST', '/api/v1/comments/report', [], ['id' => $cid, 'reason' => 'spam', 'visitor_id' => 'v-b'], [], '10.1.0.2');
    truthy(($r3['data']['auto_hidden'] ?? null) === true && ($r3['data']['status'] ?? '') === 'pending',
        '第二人举报应自动下架');
    // 已下架的评论不能再被点赞 / 举报
    [$s4, $x] = $hit('POST', '/api/v1/comments/like', [], ['id' => $cid, 'visitor_id' => 'v-c'], [], '10.1.0.3');
    truthy($s4 === 409 && $code($x) === 40914, 'pending 评论应拒绝互动：' . $s4 . ' ' . $code($x));
});

check('审核队列：被举报评论排在前，需 admin 权限，总数同口径', function () use ($hit, $cid, $admin): void {
    [$s, $q] = $hit('GET', '/api/v1/comments/queue', [], [], $admin);
    truthy($s === 200, '管理员读取队列失败 ' . $s);
    truthy(($q['data']['total'] ?? 0) >= 1, '队列总数应至少 1');
    $first = (array) ($q['data']['items'][0] ?? []);
    truthy(($first['id'] ?? 0) === $cid && ($first['reports'] ?? 0) === 2, '队首应是刚被举报的评论');
    [$anonS] = $hit('GET', '/api/v1/comments/queue');
    truthy($anonS === 401 || $anonS === 403, '匿名不应能读审核队列，实际 ' . $anonS);
});

check('按主体节流：登录身份优先于 IP、超频 42903、无主体放行', function () use ($root): void {
    // 独立站点：避免与上面的写入共享计数窗口。
    $p2 = 'it_';
    dropTables($p2);
    try {
        $k2 = isolatedKernel(tempSite('throttle'), $p2, [
            'api' => ['expose_write' => true],
            'comment' => ['auto_publish' => true, 'throttle' => ['enabled' => true, 'max' => 2, 'window' => 600]],
        ]);
        $k2->boot();
        $admin = ['Authorization' => 'Bearer test-admin-token'];
        /** @param array<string,string> $h */
        $post = static function (string $ip, array $h = []) use ($k2): int {
            $r = $k2->handle(new Request('POST', '/api/v1/comments', [], $h, [],
                json_encode(['post_slug' => 'first-post', 'body' => '节流测试 ' . $ip]), $ip));
            return $r->status();
        };
        $a = $post('10.9.0.1', $admin);
        $b = $post('10.9.0.2', $admin);   // 换 IP 但仍是同一个登录账号
        $c = $post('10.9.0.3', $admin);
        truthy($a === 200 && $b === 200, '阈值内两次应放行：' . $a . ',' . $b);
        truthy($c === 429, '同一账号换 IP 仍应被节流（防绕过），实际 ' . $c);
        // 匿名不同 IP = 不同主体，有独立额度
        truthy($post('10.9.1.1') === 200, '匿名不同 IP 不应共用登录账号的额度');
        // 主体完全不可识别（无 IP、无令牌、无 visitor_id）：放行，不把匿名写入变成 429
        $r = $k2->handle(new Request('POST', '/api/v1/comments', [], [], [],
            json_encode(['post_slug' => 'first-post', 'body' => '无 IP']), null));
        truthy($r->status() === 200, '无 IP 的匿名写入不应被节流挡掉，实际 ' . $r->status());
    } finally {
        dropTables($p2);
    }
});

check('内存降级：拔库后互动能力仍可用，且队列口径不缩水', function () use ($root): void {
    MemoryCommentStore::reset();
    MemoryInteractionStore::reset();
    // 直接构造执行者所依赖的双实现，验证「降级不是假装能用」。
    $mem = new MemoryCommentStore();
    $store = new MemoryInteractionStore($mem);
    $id = $mem->add(['post_slug' => 'p', 'body' => 'x', 'status' => \Wx\Systems\Comment\CommentPolicy::PUBLISHED,
        'author_id' => 1, 'created_at' => date('Y-m-d H:i:s')]);
    truthy($store->vote($id, 'u:1', true)['likes'] === 1, '内存点赞失效');
    truthy($store->report($id, 'u:2', 'spam')['reports'] === 1, '内存举报失效');
    $pending = $mem->add(['post_slug' => 'p', 'body' => 'y', 'status' => \Wx\Systems\Comment\CommentPolicy::PENDING,
        'author_id' => 2, 'created_at' => date('Y-m-d H:i:s')]);
    truthy(in_array($pending, array_column($store->queue(50, 0), 'comment_id'), true),
        '内存队列漏掉纯待审评论');
});

dropTables($prefix);
