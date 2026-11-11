<?php
declare(strict_types=1);

use Wx\Core\Http\Request;
use Wx\Pages\Post\CommentEditTicket;
use Wx\Systems\Lifecycle\Fs;

/**
 * 42 · 评论编辑入口：票据签发 / 篡改拒绝 / 乐观锁复用失效。
 *
 * v0.29 把 editable 从「恒真」修成了真实判定，但文章页仍有一个洞：
 * 页面 GET 用匿名主体取列表，已登录作者看到的全是 editable=false；
 * 即便强行渲染按钮，页面表单 POST 也没有 Authorization 头，提交必然失败。
 * 这里守的就是补上这一段之后的真实行为：
 *  - 匿名无入口，登录作者有入口，且票据绑定 (评论, 作者, 版本)；
 *  - 篡改 / 过期 / 错评论一律拒绝，且失败绝不 303；
 *  - 改成功一次后旧票自然失效（edit_count 变了），不靠一次性记录表。
 */
echo "\n=== 42. 评论编辑入口：票据 / 篡改 / 乐观锁 ===\n";

$site   = tempSite('comment-edit');
$prefix = 'ce_';
dropTables($prefix);

$kernel = isolatedKernel($site, $prefix, [
    'api'   => ['expose_write' => true],
    'theme' => ['active' => 'glass-front'],
]);
$kernel->boot();
$admin = adminHeaders();

/** @return array{0:int, 1:string, 2:array<string,string>} */
$hit = static function (object $k, string $path, array $q = [], string $method = 'GET',
    array $body = [], array $h = []): array {
    $r = $k->handle(new Request($method, $path, $q, $h, $body));
    return [$r->status(), $r->body(), (array) $r->headers()];
};

$writes = [
    ['/api/v1/content/post/delete', ['slug' => 'first-post']],
    ['/api/v1/content/post/delete', ['slug' => 'second-post']],
    ['/api/v1/content/posts', ['title' => '编辑甲', 'body' => '甲正文', 'slug' => 'ce-a', 'status' => 'published']],
];
$fails = [];
foreach ($writes as [$p, $b]) {
    [$s] = $hit($kernel, $p, [], 'POST', $b, $admin);
    if ($s !== 200) $fails[] = $p . '=' . $s;
}
[$cs, $cb] = $hit($kernel, '/api/v1/comments', [], 'POST',
    ['post_slug' => 'ce-a', 'body' => '最初的评论内容', 'author' => '作者本人'], $admin);
$cid = (int) (json_decode($cb, true)['data']['id'] ?? 0);
if ($cs !== 200) $fails[] = 'comment=' . $cs;

/** 从页面里取第一个指定 hidden 字段的值（切分而非正则，避免把文件拖进正则职权域）。 */
$field = static function (string $html, string $name): string {
    $needle = 'name="' . $name . '" value="';
    $parts = explode($needle, $html, 2);
    return isset($parts[1]) ? explode('"', $parts[1], 2)[0] : '';
};

/** 打开文章页；$auth 为 true 时带管理员令牌（模拟已登录作者）。 */
$open = static function (bool $auth) use ($kernel, $admin, $field): array {
    $r = $kernel->handle(new Request('GET', '/post', ['slug' => 'ce-a'], $auth ? $admin : []));
    $html = $r->body();
    return ['status' => $r->status(), 'html' => $html,
        'token' => $field($html, '_token'), 'ticket' => $field($html, '_ticket')];
};

/** 提交编辑（不带任何身份头：页面表单本来就带不了）。 */
$submit = static function (array $body) use ($kernel): \Wx\Core\Http\Response {
    return $kernel->handle(new Request('POST', '/post', [], [], $body));
};

check('前置：文章与一条登录作者的评论写入成功', function () use ($fails, $cid): void {
    truthy($fails === [], '前置失败：' . implode(', ', $fails));
    truthy($cid > 0, '评论 id 异常：' . $cid);
});

check('匿名视角：没有编辑入口，也没有可提交的票据', function () use ($open): void {
    $page = $open(false);
    truthy($page['status'] === 200, '匿名访问文章页应 200，实际 ' . $page['status']);
    truthy(!str_contains($page['html'], 'name="_intent" value="edit"'), '匿名竟然看到了编辑入口');
    truthy($page['ticket'] === '', '匿名页面里不该出现编辑票据');
});

check('登录作者：出现编辑入口，票据绑定本人且能解析出评论与版本', function () use ($open, $site, $cid): void {
    $page = $open(true);
    truthy(str_contains($page['html'], 'details class="wx-comment__edit"'), '登录作者没有看到编辑入口');
    truthy(str_contains($page['html'], 'name="_intent" value="edit"'), '编辑表单的意图字段缺失');
    truthy($page['ticket'] !== '', '编辑入口没有票据，提交必然失败');
    truthy(str_contains($page['html'], '修改内容'), '编辑表单没有预填说明');

    $signer = CommentEditTicket::fromKernel($site);
    truthy($signer !== null, '隔离站点里签不出票据，入口是假的');
    $claim = $signer->parse($page['ticket'], time());
    truthy($claim !== null, '刚签发的票据解析失败');
    truthy($claim['cid'] === $cid && $claim['uid'] === 1 && $claim['cnt'] === 0,
        '票据没有绑定 (评论, 作者, 版本)：' . json_encode($claim));
});

check('篡改票据：403 且停留在 post 布局，绝不 303 假装成功', function () use ($open, $submit, $cid): void {
    $page = $open(true);
    $bad = substr($page['ticket'], 0, -1) . (str_ends_with($page['ticket'], 'A') ? 'B' : 'A');
    $res = $submit(['slug' => 'ce-a', '_token' => $page['token'], 'visitor_id' => 'v-test',
        '_intent' => 'edit', 'comment_id' => (string) $cid, '_ticket' => $bad, 'body' => '篡改后的内容']);
    truthy($res->status() === 403, '篡改票据应 403，实际 ' . $res->status());
    truthy(!str_contains((string) ($res->headers()['Location'] ?? ''), '/post'), '失败却发生了重定向');
    truthy(str_contains($res->body(), 'data-layout="post"'), '失败态被甩离 post 布局');
    truthy(str_contains($res->body(), '凭证'), '失败文案没有说明是凭证问题');
});

check('票据与评论不匹配：422 明确拒绝，不改动任何内容', function () use ($open, $submit, $cid): void {
    $page = $open(true);
    $res = $submit(['slug' => 'ce-a', '_token' => $page['token'], 'visitor_id' => 'v-test',
        '_intent' => 'edit', 'comment_id' => (string) ($cid + 999), '_ticket' => $page['ticket'],
        'body' => '张冠李戴的内容']);
    truthy($res->status() === 422, '票据与评论不匹配应 422，实际 ' . $res->status());
    truthy(str_contains($res->body(), '不匹配'), '没有点明票据与目标不一致');
});

check('成功编辑：303 回文章页，正文更新且 edit_count 前进', function () use ($open, $submit, $cid, $kernel, $admin): void {
    $page = $open(true);
    $res = $submit(['slug' => 'ce-a', '_token' => $page['token'], 'visitor_id' => 'v-test',
        '_intent' => 'edit', 'comment_id' => (string) $cid, '_ticket' => $page['ticket'],
        'body' => '修正后的内容']);
    truthy($res->status() === 303, '编辑成功应 303，实际 ' . $res->status());
    truthy(str_contains((string) ($res->headers()['Location'] ?? ''), '#comments'), '303 没有回到评论区');

    $seen = $open(true);
    truthy(str_contains($seen['html'], '修正后的内容'), '页面没有显示修改后的内容');

    $r = $kernel->handle(new Request('GET', '/api/v1/comments', ['post_slug' => 'ce-a'], $admin));
    truthy($r->status() === 200, '读取评论接口失败');
    $item = (array) ((json_decode($r->body(), true)['data']['items'] ?? [])[0] ?? []);
    truthy((int) ($item['edit_count'] ?? 0) === 1, 'edit_count 没有前进：' . json_encode($item));
});

check('旧票复用：版本已变，409 拒绝，且不会二次覆盖内容', function () use ($open, $submit, $cid): void {
    // 当前 edit_count=1。先拿一张 cnt=1 的票留作「旧票」，再用另一张票改到 2。
    $stale = $open(true)['ticket'];
    $page = $open(true);
    $ok = $submit(['slug' => 'ce-a', '_token' => $page['token'], 'visitor_id' => 'v-test',
        '_intent' => 'edit', 'comment_id' => (string) $cid, '_ticket' => $page['ticket'],
        'body' => '第二次修改']);
    truthy($ok->status() === 303, '第二次修改应成功，实际 ' . $ok->status());

    $page3 = $open(true);
    $res = $submit(['slug' => 'ce-a', '_token' => $page3['token'], 'visitor_id' => 'v-test',
        '_intent' => 'edit', 'comment_id' => (string) $cid, '_ticket' => $stale,
        'body' => '用旧票覆盖']);
    truthy($res->status() === 409, '旧票应 409，实际 ' . $res->status());
    truthy(str_contains($res->body(), '其他编辑'), '没有说明是并发编辑冲突');
});

check('过期票据：纯逻辑直接判死，不依赖等待', function () use ($site): void {
    $signer = CommentEditTicket::fromKernel($site);
    truthy($signer !== null, '隔离站点签不出票据');
    $ticket = $signer->issue(7, 1, 0, time());
    truthy($signer->parse($ticket, time()) !== null, '刚签发的票据应当有效');
    truthy($signer->parse($ticket, time() + 901) === null, '超过 15 分钟的票据仍然有效');
    truthy($signer->parse('', time()) === null, '空票据被当成有效');
    truthy($signer->parse($ticket . 'x', time()) === null, '追加字符的票据被接受');
});

check('无密钥时 fail-closed：不签发、不渲染入口，而不是退回无签名票据', function () use ($site): void {
    $broken = $site . '/storage-as-file';
    file_put_contents($broken, 'x');   // storage 路径被一个普通文件占住，密钥无法落盘
    $signer = CommentEditTicket::fromKernel($broken);
    truthy($signer === null, '存储不可写时仍然签发了票据');
});

dropTables($prefix);
Fs::removeDir($site);
