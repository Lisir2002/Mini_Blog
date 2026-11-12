<?php
declare(strict_types=1);

use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Http\Request;
use Wx\Pages\ContentAdmin\ContentAdminPlacement;
use Wx\Systems\Lifecycle\Fs;

/**
 * 69b · 排期与推荐位：运行时闭环
 *
 * 与 69 组同属一件事的两半：本组从真实 HTTP + 真实 MariaDB 取证，锁住
 * 参数级入口（publish_at / pinned / weight）、定时可见性、权限现场问、
 * 字段级 ACL 隔离与草稿残留清理。
 */
echo "\n=== 69b. 排期与推荐位：运行时闭环 ===\n";

$root = dirname(__DIR__, 2);

// ---------- 运行时：真实 HTTP + 真实 MariaDB ----------
$site   = tempSite('placement');
$prefix = 'pl_';
dropTables($prefix);
$k = isolatedKernel($site, $prefix, [
    'theme' => ['active' => 'glass'],
    'api'   => ['expose_write' => true, 'tokens' => [
        't-author-token' => ['role' => 'author', 'username' => 'author', 'user_id' => 9],
    ]],
]);
$k->boot();
$reg    = $k->container()->resolve(ApiRegistryInterface::class);
$admin  = ['Cookie' => 'wx_session=test-admin-token'];
$author = ['Cookie' => 'wx_session=t-author-token'];
$ident  = ['user_id' => 1, 'username' => 'admin', 'role' => 'admin'];

foreach (['pl-a', 'pl-b'] as $slug) {
    $reg->execute('content.create', ['slug' => $slug, 'title' => '测试 ' . $slug,
        'body' => '正文', 'status' => 'published', 'author' => 'admin']);
}

/** @return array{0:int, 1:string, 2:array<string,string>} */
$hit = static function (string $path, array $q = [], string $method = 'GET',
    array $body = [], array $h = []) use ($k): array {
    $r = $k->handle(new Request($method, $path, $q, $h, $body));
    return [$r->status(), $r->body(), $r->headers()];
};
$field = static function (string $html, string $name): string {
    return preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html, $m)
        ? (string) $m[1] : '';
};
$ticketOf = static function (array $q, array $cookie) use ($hit, $field): string {
    [, $page] = $hit('/console/content', $q, 'GET', [], $cookie);
    return $field($page, '_form_ticket');
};
/** @return array<string, mixed> */
// 带管理员身份读 status=any：草稿对匿名会被可见性过滤掉，
// 「列表里找不到」不等于「字段没清」。物理状态断言必须看得见草稿。
$rowOf = static function (string $slug) use ($reg, $ident): array {
    foreach ((array) ($reg->execute('content.posts', ['status' => 'any', 'limit' => 50,
        '_identity' => $ident])['items'] ?? []) as $it) {
        if (is_array($it) && ($it['slug'] ?? '') === $slug) return $it;
    }
    return [];
};
$future = date('Y-m-d H:i:s', time() + 86400);

check('排期闭环：303 带回 focus，未到点前台 / 列表 / 站点地图都看不到', function () use ($hit, $ticketOf, $admin, $reg, $rowOf, $future): void {
    [, $page] = $hit('/console/content', ['focus' => 'pl-a'], 'GET', [], $admin);
    truthy(str_contains($page, 'name="publish_at"'), '编辑面板没有时间输入');
    $t = $ticketOf(['focus' => 'pl-a'], $admin);
    [$s, , $h] = $hit('/console/content', [], 'POST', [
        'op' => 'schedule', 'slug' => 'pl-a', 'publish_at' => $future, '_form_ticket' => $t,
    ], $admin);
    truthy($s === 303, '设定排期应 303，实际 ' . $s);
    truthy(str_contains((string) ($h['Location'] ?? ''), 'focus=pl-a'), '303 丢了 focus，刷新看不到刚设的值');

    [$anon] = $hit('/post', ['slug' => 'pl-a']);
    truthy($anon === 404, '未到点的定时文章对匿名可见');
    $leaked = false;
    foreach ((array) ($reg->execute('content.posts', ['status' => 'published', 'limit' => 50])['items'] ?? []) as $it) {
        if (is_array($it) && ($it['slug'] ?? '') === 'pl-a') $leaked = true;
    }
    truthy(!$leaked, '定时文章混进了 published 列表');
    [$ms, $map] = $hit('/sitemap.xml');
    truthy($ms === 200 && !str_contains($map, 'pl-a'), '定时文章被站点地图提前收录');
    $row = $rowOf('pl-a');
    truthy(($row['publish_at'] ?? null) !== null && strtotime((string) $row['publish_at']) > time(),
        'publish_at 没有落到库里');
    [, $again] = $hit('/console/content', ['focus' => 'pl-a'], 'GET', [], $admin);
    truthy(str_contains($again, '已排期'), '面板没有回显已排期状态');
});

check('取消排期与非法时间：留空立即可见，解析不了明确报错且不静默上线', function () use ($hit, $ticketOf, $admin, $rowOf): void {
    $t = $ticketOf(['focus' => 'pl-a'], $admin);
    [$s, $body] = $hit('/console/content', [], 'POST', [
        'op' => 'schedule', 'slug' => 'pl-a', 'publish_at' => 'not-a-date', '_form_ticket' => $t,
    ], $admin);
    truthy($s === 422, '无法解析的时间应 422，实际 ' . $s);
    truthy(str_contains($body, '无法解析'), '没有说明时间解析失败');
    [$anon] = $hit('/post', ['slug' => 'pl-a']);
    truthy($anon === 404, '非法时间把排期清掉了，等于静默立即发布');

    [$s] = $hit('/console/content', [], 'POST', [
        'op' => 'schedule', 'slug' => 'pl-a', 'publish_at' => '', '_form_ticket' => $t,
    ], $admin);
    truthy($s === 303, '取消排期应 303，实际 ' . $s);
    [$anon] = $hit('/post', ['slug' => 'pl-a']);
    truthy($anon === 200, '留空取消排期后文章仍不可见');
    $cleared = $rowOf('pl-a');
    truthy(array_key_exists('publish_at', $cleared) && $cleared['publish_at'] === null,
        'publish_at 没有被清空');
});

check('推荐位闭环：置顶 / 权重落库，越界与非法值显式拒绝', function () use ($hit, $ticketOf, $admin, $reg, $rowOf): void {
    $t = $ticketOf(['focus' => 'pl-a'], $admin);
    [$s, $body] = $hit('/console/content', [], 'POST', [
        'op' => 'pin:on', 'slug' => 'pl-a', 'weight' => '1001', '_form_ticket' => $t,
    ], $admin);
    truthy($s === 422 && str_contains($body, '未做静默截断'), '权重越界没有显式拒绝');
    [$s, $body] = $hit('/console/content', [], 'POST', [
        'op' => 'pin:on', 'slug' => 'pl-a', 'weight' => 'abc', '_form_ticket' => $t,
    ], $admin);
    truthy($s === 422 && str_contains($body, '权重必须是整数'), '非整数权重没有显式拒绝');

    [$s, , $h] = $hit('/console/content', [], 'POST', [
        'op' => 'pin:on', 'slug' => 'pl-a', 'weight' => '42', '_form_ticket' => $t,
    ], $admin);
    truthy($s === 303, '保存推荐位应 303，实际 ' . $s);
    truthy(str_contains((string) ($h['Location'] ?? ''), 'focus=pl-a'), '303 丢了 focus');
    $ids = [];
    foreach ((array) ($reg->execute('content.posts', ['status' => 'any', 'pinned' => true,
        'limit' => 50])['items'] ?? []) as $it) {
        if (is_array($it)) $ids[] = (string) ($it['slug'] ?? '');
    }
    truthy(in_array('pl-a', $ids, true), 'pinned=1 取不到刚置顶的文章');
    truthy((int) ($rowOf('pl-a')['sort_weight'] ?? -1) === 42, '权重没有落到库里');
    [, $page] = $hit('/console/content', ['focus' => 'pl-a'], 'GET', [], $admin);
    truthy(str_contains($page, '已置顶，权重 42'), '面板没有回显置顶与权重');

    [$s] = $hit('/console/content', [], 'POST', [
        'op' => 'pin:off', 'slug' => 'pl-a', 'weight' => '42', '_form_ticket' => $t,
    ], $admin);
    truthy($s === 303, '取消置顶应 303，实际 ' . $s);
    truthy(($rowOf('pl-a')['pinned'] ?? true) === false || (int) ($rowOf('pl-a')['pinned'] ?? 1) === 0,
        '取消置顶没有落库');
});

check('权限现场问：author 看不到排期入口，手工提交 403，但改推荐位可以', function () use ($hit, $field, $author): void {
    [$s, $page] = $hit('/console/content', ['focus' => 'pl-a'], 'GET', [], $author);
    truthy($s === 200, 'author 应可进入内容页，实际 ' . $s);
    truthy(!str_contains($page, 'name="publish_at"'), 'author 看到了无权的排期表单');
    truthy(str_contains($page, 'content.publish'), '没有说明缺哪个权限点');
    truthy(str_contains($page, 'name="weight"'), 'author 有 content.edit，推荐位表单却没渲染');
    $t = $field($page, '_form_ticket');
    [$s, $body] = $hit('/console/content', [], 'POST', [
        'op' => 'schedule', 'slug' => 'pl-a', 'publish_at' => '', '_form_ticket' => $t,
    ], $author);
    truthy($s === 403, 'author 手工排期应 403，实际 ' . $s);
    truthy(str_contains($body, 'fail-closed'), '没有说明是权限 fail-closed');
    [$s] = $hit('/console/content', [], 'POST', [
        'op' => 'pin:on', 'slug' => 'pl-b', 'weight' => '7', '_form_ticket' => $t,
    ], $author);
    truthy($s === 303, 'author 有 content.edit 却改不了推荐位，实际 ' . $s);
});

check('字段级 ACL：拒绝 pinned 时推荐位 403，正文保存不受牵连', function () use ($hit, $ticketOf, $admin, $reg, $field): void {
    $reg->execute('permission.acl.set', [
        'type' => 'post', 'id' => 'pl-b', 'subject' => '*',
        'capability' => 'content.edit', 'field' => 'pinned', 'allow' => false,
    ]);
    $t = $ticketOf(['focus' => 'pl-b'], $admin);
    [$s, $body] = $hit('/console/content', [], 'POST', [
        'op' => 'pin:off', 'slug' => 'pl-b', 'weight' => '0', '_form_ticket' => $t,
    ], $admin);
    truthy($s === 403, '字段级拒绝应 403，实际 ' . $s);
    truthy(str_contains($body, '字段写入被拒绝'), '没有说明是哪个字段被拒');

    [, $wp] = $hit('/console/write', ['slug' => 'pl-b'], 'GET', [], $admin);
    $wt = $field($wp, '_form_ticket');
    [$s] = $hit('/console/write', [], 'POST', [
        'slug' => 'pl-b', 'mode' => 'edit', 'title' => '改过的标题', 'body' => '改过的正文',
        'status' => 'published', '_form_ticket' => $wt,
    ], $admin);
    truthy($s === 303, '正文保存被字段级 ACL 连累了（说明新字段夹带进了正文表单）');
    $post = (array) $reg->execute('content.post', ['slug' => 'pl-b']);
    truthy(($post['title'] ?? '') === '改过的标题', '正文没有真的保存');
});

check('改回草稿必须清掉 publish_at，不能留「还没上线」的残留', function () use ($hit, $ticketOf, $admin, $reg, $rowOf, $ident, $future): void {
    $t = $ticketOf(['focus' => 'pl-a'], $admin);
    [$s] = $hit('/console/content', [], 'POST', [
        'op' => 'schedule', 'slug' => 'pl-a', 'publish_at' => $future, '_form_ticket' => $t,
    ], $admin);
    truthy($s === 303, '重新排期失败');
    // 写作页读不到定时文章，但 API 直调该更新接口改 status 是真实存在的路径。
    $reg->execute('content.update', ['slug' => 'pl-a', 'status' => 'draft', '_identity' => $ident]);
    $drafted = $rowOf('pl-a');
    truthy(array_key_exists('publish_at', $drafted) && $drafted['publish_at'] === null,
        '改回草稿仍留着未来的 publish_at');
    $reg->execute('content.transition', ['slug' => 'pl-a', 'to' => 'published', '_identity' => $ident]);
    [$anon] = $hit('/post', ['slug' => 'pl-a']);
    truthy($anon === 200, '残留让重新发布的文章莫名其妙「还没上线」');
});

check('票据与动作白名单：无票 401、未知动作 422、正文表单不夹带新字段', function () use ($hit, $admin, $root, $k, $reg): void {
    [$s, $body] = $hit('/console/content', [], 'POST', [
        'op' => 'schedule', 'slug' => 'pl-a', 'publish_at' => '',
    ], $admin);
    truthy($s === 401 && str_contains($body, '表单已失效'), '无票据的排期提交没有被拦');

    $bad = ContentAdminPlacement::run($k, $reg,
        ['op' => 'pin:maybe', 'slug' => 'pl-a'], ['user_id' => 1, 'username' => 'admin', 'role' => 'admin']);
    truthy($bad['status'] === 422 && str_contains($bad['message'], 'pin:on'),
        '未知动作没有列出合法枚举');

    $write = (string) file_get_contents($root . '/pages/Write/WriteAction.php');
    truthy(!str_contains($write, "'pinned'") && !str_contains($write, "'weight'"),
        '排期 / 推荐位字段回流进了正文表单，会连累字段级 ACL');
    $placement = (string) file_get_contents($root . '/pages/ContentAdmin/ContentAdminPlacement.php');
    truthy(!str_contains($placement, "'checkbox'"), '置顶状态不该依赖复选框');
});

dropTables($prefix);
Fs::removeDir($site);
