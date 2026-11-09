<?php
declare(strict_types=1);

use Wx\Core\Http\Request;
use Wx\Systems\Comment\CommentTree;
use Wx\Systems\Comment\MemoryCommentStore;

/**
 * 20a · 评论树 —— 结构、回评约束、软删保链、审核状态机。
 *
 * 这一组只验证「评论之间的关系是否成立」，不碰附件与表情。
 */
echo "\n=== 20a. 评论：树结构 / 回评 / 软删 / 审核 ===\n";

$site   = tempSite('comment');
$prefix = 'cm_';
dropTables($prefix);
$k = isolatedKernel($site, $prefix);
$k->boot();
$admin = ['Authorization' => 'Bearer test-admin-token'];

check('树构建：扁平列表按父子关系装配，缺失父节点提升为根', function (): void {
    MemoryCommentStore::reset();
    $rows = [
        ['id' => 1, 'parent_id' => 0], ['id' => 2, 'parent_id' => 1],
        ['id' => 3, 'parent_id' => 2], ['id' => 4, 'parent_id' => 0],
        ['id' => 5, 'parent_id' => 99],
    ];
    $tree = CommentTree::build($rows);
    truthy(count($tree) === 3, '根节点数应为 3，实际 ' . count($tree));
    truthy($tree[0]['children'][0]['children'][0]['id'] === 3, '三层嵌套装配错误');
    truthy($tree[2]['id'] === 5, '父节点缺失时应提升为根');
    truthy(CommentTree::pathFor(null, 7) === '/7/', '根路径应为 /7/');
    truthy(CommentTree::pathFor(['path' => '/7/'], 9) === '/7/9/', '子路径拼接错误');
    truthy(CommentTree::depthFor(null) === 0 && CommentTree::depthFor(['depth' => 2]) === 3, '深度计算错误');
});

check('发表根评论：深度 0、路径回填、公开条数 +1', function () use ($k, $admin): void {
    $r = $k->handle(new Request('POST', '/api/v1/comments', [], $admin, [],
        json_encode(['post_slug' => 'first-post', 'author' => '甲', 'body' => '第一条评论 :smile:'])));
    $body = json_decode($r->body(), true);
    truthy(($body['code'] ?? null) === 0, '发评论失败：' . $r->body());
    $id = (int) ($body['data']['id'] ?? 0);
    truthy(($body['data']['depth'] ?? -1) === 0, '根评论深度应为 0');
    $list = json_decode($k->handle(new Request('GET', '/api/v1/comments', ['post_slug' => 'first-post']))->body(), true);
    truthy(($list['data']['total'] ?? 0) >= 1, '公开条数未更新');
    $node = array_values(array_filter($list['data']['items'], static fn(array $i): bool => $i['id'] === $id))[0] ?? null;
    truthy($node !== null, '列表中找不到刚发的评论');
    // 路径是内部结构，接口不输出；直接问存储，避免「为了让测试通过而泄漏内部字段」。
    $stored = $k->container()->resolve(\Wx\Contracts\CommentStoreInterface::class)->find($id);
    truthy(($stored['path'] ?? '') === '/' . $id . '/', '路径未回填');
    truthy(!isset($node['path']) && !isset($node['ip_hash']) && !isset($node['author_id']), '出站不应携带内部字段');
    $GLOBALS['wx_cm_root'] = $id;
});

check('回评：父评论同属一篇、深度 +1、树里出现子节点', function () use ($k, $admin): void {
    $parent = (int) ($GLOBALS['wx_cm_root'] ?? 0);
    $r = $k->handle(new Request('POST', '/api/v1/comments/reply', [], $admin, [],
        json_encode(['post_slug' => 'first-post', 'parent_id' => $parent, 'body' => '回复一下 :tada:'])));
    $body = json_decode($r->body(), true);
    truthy(($body['code'] ?? null) === 0, '回评失败：' . $r->body());
    truthy(($body['data']['depth'] ?? 0) === 1, '回评深度应为 1');
    $list = json_decode($k->handle(new Request('GET', '/api/v1/comments', ['post_slug' => 'first-post']))->body(), true);
    $root = array_values(array_filter($list['data']['tree'], static fn(array $n): bool => $n['id'] === $parent))[0] ?? null;
    truthy($root !== null && count($root['children']) === 1, '树中未挂上子回复');
});

check('回评约束：跨文章、深于上限、父评论不存在分别被拒', function () use ($k, $admin, $prefix): void {
    $parent = (int) ($GLOBALS['wx_cm_root'] ?? 0);
    $cross = $k->handle(new Request('POST', '/api/v1/comments/reply', [], $admin, [],
        json_encode(['post_slug' => 'second-post', 'parent_id' => $parent, 'body' => '跨文章'])));
    $body = json_decode($cross->body(), true);
    truthy($cross->status() === 422 && ($body['code'] ?? null) === 42201,
        '跨文章回评应被拒：' . json_encode($body, JSON_UNESCAPED_UNICODE));

    $missing = json_decode($k->handle(new Request('POST', '/api/v1/comments/reply', [], $admin, [],
        json_encode(['post_slug' => 'first-post', 'parent_id' => 999999, 'body' => '孤儿'])))->body(), true);
    truthy(($missing['code'] ?? null) === 40403, '父评论不存在应返回 40403');
});

check('深度上限：超过 comment.max_depth 的回评被拒', function () use ($site, $prefix): void {
    $k = isolatedKernel($site, $prefix, ['comment' => ['max_depth' => 1]]);
    $k->boot();
    $h = ['Authorization' => 'Bearer test-admin-token'];
    $root = json_decode($k->handle(new Request('POST', '/api/v1/comments', [], $h, [],
        json_encode(['post_slug' => 'first-post', 'body' => '根'])))->body(), true);
    $rootId = (int) ($root['data']['id'] ?? 0);
    $one = json_decode($k->handle(new Request('POST', '/api/v1/comments/reply', [], $h, [],
        json_encode(['post_slug' => 'first-post', 'parent_id' => $rootId, 'body' => '一层'])))->body(), true);
    truthy(($one['code'] ?? null) === 0, '一层回复应允许');
    $two = json_decode($k->handle(new Request('POST', '/api/v1/comments/reply', [], $h, [],
        json_encode(['post_slug' => 'first-post', 'parent_id' => (int) $one['data']['id'], 'body' => '两层'])))->body(), true);
    truthy(($two['code'] ?? null) === 42201, '超过深度上限应被拒');
});

check('软删：正文清空、状态 deleted、子回复仍在树中', function () use ($k, $admin): void {
    $parent = (int) ($GLOBALS['wx_cm_root'] ?? 0);
    $r = json_decode($k->handle(new Request('POST', '/api/v1/comments/delete', [], $admin, [],
        json_encode(['id' => $parent])))->body(), true);
    truthy(($r['data']['deleted'] ?? false) === true, '删除未生效');
    $again = json_decode($k->handle(new Request('POST', '/api/v1/comments/delete', [], $admin, [],
        json_encode(['id' => $parent])))->body(), true);
    truthy(($again['data']['already'] ?? false) === true, '重复删除应幂等');

    $list = json_decode($k->handle(new Request('GET', '/api/v1/comments', ['post_slug' => 'first-post']))->body(), true);
    $node = array_values(array_filter($list['data']['items'], static fn(array $i): bool => $i['id'] === $parent))[0] ?? null;
    truthy($node !== null && $node['deleted'] === true, '已删除评论应保留占位');
    truthy((string) $node['body'] === '', '已删除评论正文应清空');
    $treeNode = array_values(array_filter($list['data']['tree'], static fn(array $n): bool => $n['id'] === $parent))[0] ?? null;
    truthy($treeNode !== null && count($treeNode['children']) === 1, '子回复不应随父评论消失');
});

check('审核：合法迁移生效，非法目标与已删除评论被 40901 拒绝', function () use ($k, $admin): void {
    $parent = (int) ($GLOBALS['wx_cm_root'] ?? 0);
    $bad = json_decode($k->handle(new Request('POST', '/api/v1/comments/moderate', [], $admin, [],
        json_encode(['id' => $parent, 'status' => 'published'])))->body(), true);
    truthy(($bad['code'] ?? null) === 40901, '已删除评论不应被审核回来');

    $pending = $k->handle(new Request('POST', '/api/v1/comments', [], $admin, [],
        json_encode(['post_slug' => 'first-post', 'body' => '待审'])));
    $id = (int) (json_decode($pending->body(), true)['data']['id'] ?? 0);
    $ok = json_decode($k->handle(new Request('POST', '/api/v1/comments/moderate', [], $admin, [],
        json_encode(['id' => $id, 'status' => 'hidden'])))->body(), true);
    truthy(($ok['code'] ?? null) === 0, 'hidden 迁移应允许');
    $invalid = json_decode($k->handle(new Request('POST', '/api/v1/comments/moderate', [], $admin, [],
        json_encode(['id' => $id, 'status' => 'deleted'])))->body(), true);
    truthy(($invalid['code'] ?? null) === 42201, '非法目标状态应返回 42201');
});

check('可见性：hidden 对匿名不可见，对管理员可见', function () use ($k, $admin): void {
    $anon = json_decode($k->handle(new Request('GET', '/api/v1/comments', ['post_slug' => 'first-post']))->body(), true);
    $mine = json_decode($k->handle(new Request('GET', '/api/v1/comments', ['post_slug' => 'first-post'], $admin))->body(), true);
    truthy(count($mine['data']['items']) >= count($anon['data']['items']), '管理员应看到不少于匿名的条目');
    $hidden = array_values(array_filter($mine['data']['items'], static fn(array $i): bool => $i['status'] === 'hidden'));
    truthy($hidden !== [], '管理员应看到 hidden 条目');
    $anonHidden = array_values(array_filter($anon['data']['items'], static fn(array $i): bool => $i['status'] === 'hidden'));
    truthy($anonHidden === [], '匿名不应看到 hidden 条目');
});

check('内容守卫：空正文 / 超长 / 链接过多被拒，未知文章返回 40403', function () use ($k, $admin): void {
    $cases = [
        ['body' => '   ', 'label' => '空正文'],
        ['body' => str_repeat('字', 5001), 'label' => '超长'],
        ['body' => 'http://a.test http://b.test http://c.test http://d.test', 'label' => '链接过多'],
    ];
    foreach ($cases as $case) {
        $r = json_decode($k->handle(new Request('POST', '/api/v1/comments', [], $admin, [],
            json_encode(['post_slug' => 'first-post'] + $case)))->body(), true);
        truthy(($r['code'] ?? null) === 42201, $case['label'] . ' 应返回 42201');
    }
    $orphan = json_decode($k->handle(new Request('POST', '/api/v1/comments', [], $admin, [],
        json_encode(['post_slug' => 'no-such-post', 'body' => '孤儿评论'])))->body(), true);
    truthy(($orphan['code'] ?? null) === 40403, '文章不存在时应返回 40403');
});

check('父评论被隐藏：回复整枝隐藏，不被提升为根', function () use ($k, $admin): void {
    $parent = json_decode($k->handle(new Request('POST', '/api/v1/comments', [], $admin, [],
        json_encode(['post_slug' => 'first-post', 'body' => '将被隐藏的父评论'])))->body(), true);
    $pid = (int) ($parent['data']['id'] ?? 0);
    $reply = json_decode($k->handle(new Request('POST', '/api/v1/comments/reply', [], $admin, [],
        json_encode(['post_slug' => 'first-post', 'parent_id' => $pid, 'body' => '挂在隐藏父下的回复'])))->body(), true);
    $rid = (int) ($reply['data']['id'] ?? 0);
    truthy($pid > 0 && $rid > 0, '未能造出父评论与回复：' . json_encode([$parent['message'] ?? '', $reply['message'] ?? '']));

    $hidden = json_decode($k->handle(new Request('POST', '/api/v1/comments/moderate', [], $admin, [],
        json_encode(['id' => $pid, 'status' => 'hidden'])))->body(), true);
    truthy(($hidden['code'] ?? null) === 0, '隐藏父评论失败');

    // 匿名视角：父不可见，回复也必须一起消失；不能以「孤儿」身份出现在根层。
    $anon = json_decode($k->handle(new Request('GET', '/api/v1/comments', ['post_slug' => 'first-post']))->body(), true);
    $ids = array_map(static fn(array $i): int => (int) $i['id'], (array) ($anon['data']['items'] ?? []));
    truthy(!in_array($rid, $ids, true), '父评论隐藏后，回复仍出现在列表里');
    $roots = array_map(static fn(array $n): int => (int) $n['id'], (array) ($anon['data']['tree'] ?? []));
    truthy(!in_array($rid, $roots, true), '回复被错误地提升为根节点');
});

dropTables($prefix);
\Wx\Systems\Lifecycle\Fs::removeDir($site);
