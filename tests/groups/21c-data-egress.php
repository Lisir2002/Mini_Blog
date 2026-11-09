<?php
declare(strict_types=1);

use Wx\Core\Http\Request;

/**
 * 21c · 出站字段 —— 物理列不等于接口字段。
 *
 * 这条守卫防的是「加了一列，接口就悄悄多一个字段」：
 * 评论行里有 ip_hash / path / author_id，它们一个都不该出现在响应里。
 */
echo "\n=== 21c. 出站裁剪：响应字段必须 ⊆ 契约字段 ===\n";

$site   = tempSite('data-egress');
$prefix = 'eg_';
dropTables($prefix);
$k = isolatedKernel($site, $prefix);
$k->boot();
$admin = ['Authorization' => 'Bearer test-admin-token'];

check('评论列表：不携带 ip_hash / path / author_id / deleted_at', function () use ($k, $admin): void {
    $post = $k->handle(new Request('POST', '/api/v1/comments', [], $admin, [],
        json_encode(['post_slug' => 'first-post', 'author' => '探针', 'body' => '检查出站字段'])));
    $created = json_decode($post->body(), true);
    truthy(($created['code'] ?? null) === 0, '发评论失败：' . $post->body());

    $list = json_decode($k->handle(new Request('GET', '/api/v1/comments', ['post_slug' => 'first-post']))->body(), true);
    $items = (array) ($list['data']['items'] ?? []);
    truthy($items !== [], '列表为空，无法校验出站字段');
    $item = $items[0];
    foreach (['ip_hash', 'path', 'root_id', 'author_id', 'deleted_at', 'slug'] as $internal) {
        truthy(!array_key_exists($internal, $item), '出站泄漏了内部字段：' . $internal);
    }
});

check('评论条目字段集恰好等于契约声明，多一个都算破坏契约', function () use ($k): void {
    $list = json_decode($k->handle(new Request('GET', '/api/v1/comments', ['post_slug' => 'first-post']))->body(), true);
    $item = (array) (($list['data']['items'] ?? [])[0] ?? []);
    $expected = ['id', 'parent_id', 'depth', 'author', 'body', 'status', 'created_at',
        'deleted', 'own', 'editable', 'deletable', 'moderatable', 'tokens'];
    $actual = array_keys($item);
    sort($expected);
    sort($actual);
    truthy($actual === $expected, '字段集不一致：多出 [' . implode(',', array_diff($actual, $expected))
        . ']，缺少 [' . implode(',', array_diff($expected, $actual)) . ']');
});

check('列表与树使用同一份呈现结果，不出现第二套字段', function () use ($k): void {
    $list = json_decode($k->handle(new Request('GET', '/api/v1/comments', ['post_slug' => 'first-post']))->body(), true);
    $node = (array) (($list['data']['tree'] ?? [])[0] ?? []);
    foreach (['ip_hash', 'path', 'author_id'] as $internal) {
        truthy(!array_key_exists($internal, $node), '树节点泄漏了内部字段：' . $internal);
    }
});
