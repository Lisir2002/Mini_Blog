<?php
declare(strict_types=1);

use Wx\Core\Http\Request;
use Wx\Systems\Lifecycle\Fs;

/**
 * 39b · 文章页互动入口运行时：真实 MySQL + 真实主题渲染。
 *
 * 验的是「接上独立页与主题之后」才显形的行为：
 *  - liked 是否真的从「恒 false」变成按主体回显（v0.29 的空头支票在这一层被兑现）；
 *  - 点赞是否同主体幂等、跨主体独立；
 *  - 失败态是否留在 post 布局并给真实状态码，而不是 303 假成功或白屏；
 *  - 不可互动目标（不存在的评论）是否被明确拒绝。
 *
 * 目标文章上刻意只留一条评论：模板按 `data-likes` / `data-liked` 标记节点，
 * 多条会让断言产生「取到了哪一条」的歧义，守卫本身也会变成不可复现的。
 */
echo "\n=== 39b. 文章页互动运行时：回显 / 幂等 / 失败态 ===\n";

$site   = tempSite('post-interactions');
$prefix = 'pv_';
dropTables($prefix);

$jsonKernel = isolatedKernel($site, $prefix, ['api' => ['expose_write' => true]]);
$jsonKernel->boot();
$htmlKernel = isolatedKernel($site, $prefix, [
    'api'   => ['expose_write' => true],
    'theme' => ['active' => 'glass-front'],
]);
$htmlKernel->boot();
$admin = adminHeaders();

/** @return array{0:int, 1:string, 2:string, 3:array<string,string>} */
$hit = static function (object $k, string $path, array $q = [], string $method = 'GET',
    array $body = [], array $h = []): array {
    $r = $k->handle(new Request($method, $path, $q, $h, $body));
    return [$r->status(), $r->contentType(), $r->body(), (array) $r->headers()];
};

$writes = [
    ['/api/v1/content/post/delete', ['slug' => 'first-post']],
    ['/api/v1/content/post/delete', ['slug' => 'second-post']],
    ['/api/v1/content/posts', ['title' => '互动甲', 'body' => '甲正文', 'slug' => 'pv-a', 'status' => 'published']],
];
$fails = [];
foreach ($writes as [$p, $b]) {
    [$s] = $hit($jsonKernel, $p, [], 'POST', $b, $admin);
    if ($s !== 200) $fails[] = $p . '=' . $s;
}
[$cs, , $cb] = $hit($jsonKernel, '/api/v1/comments', [], 'POST',
    ['post_slug' => 'pv-a', 'body' => '唯一一条评论', 'author' => '读者'], $admin);
$cid = (int) (json_decode($cb, true)['data']['id'] ?? 0);   // 接口统一走 Envelope，id 在 data 里
if ($cs !== 200) $fails[] = 'comment=' . $cs;

check('前置：文章与评论写入成功', function () use ($fails, $cid): void {
    truthy($fails === [], '前置失败：' . implode(', ', $fails));
    truthy($cid > 0, '评论 id 异常：' . $cid);
});

/** 打开文章页：返回 html / token / visitor / cookie / likes / liked */
$open = static function (string $cookie = '') use ($htmlKernel): array {
    $h = $cookie !== '' ? ['Cookie' => $cookie] : [];
    $r = $htmlKernel->handle(new Request('GET', '/post', ['slug' => 'pv-a'], $h));
    $html = $r->body();
    $setCookie = (string) ($r->headers()['Set-Cookie'] ?? '');
    // 用切分而不是正则：正则会把本文件拖进 validation 职权域。
    $visitor = '';
    $vp = explode('wx_visitor=', $setCookie, 2);
    if (isset($vp[1])) $visitor = rawurldecode(explode(';', $vp[1], 2)[0]);
    $token = '';
    $tp = explode('name="_token" value="', $html, 2);
    if (isset($tp[1])) $token = explode('"', $tp[1], 2)[0];
    $likes = -1;
    $lp = explode('data-likes="', $html, 2);
    if (isset($lp[1])) $likes = (int) explode('"', $lp[1], 2)[0];
    $liked = null;
    $dp = explode('data-liked="', $html, 2);
    if (isset($dp[1])) $liked = explode('"', $dp[1], 2)[0] === '1';

    return ['html' => $html, 'token' => $token, 'visitor' => $visitor,
        'cookie' => $setCookie, 'likes' => $likes, 'liked' => $liked];
};

/** 提交一次互动 */
$submit = static function (array $body, string $cookie) use ($htmlKernel): \Wx\Core\Http\Response {
    return $htmlKernel->handle(new Request('POST', '/post', [],
        $cookie !== '' ? ['Cookie' => $cookie] : [], $body));
};

// 固定一个主体 A，跨用例沿用：不传 Cookie 会生成新 visitor，
// 那会让「同一个人的已赞状态」在用例之间凭空消失，断言就失去意义。
$sessionA = $open();
$cookieA  = 'wx_visitor=' . rawurlencode($sessionA['visitor']);

check('首次访问：下发稳定 Cookie、给出令牌与 visitor_id，评论默认未点赞', function () use ($open): void {
    $page = $open();
    truthy($page['visitor'] !== '', '首次访问没有下发 wx_visitor Cookie');
    truthy($page['token'] !== '', '页面没有 令牌 令牌');
    truthy(str_contains($page['html'], 'data-layout="post"'), '文章页未使用 post 布局');
    truthy(str_contains($page['html'], 'name="_intent" value="like"'), '缺少点赞表单');
    truthy(str_contains($page['html'], 'name="_intent" value="report"'), '缺少举报表单');
    truthy(str_contains($page['html'], 'name="visitor_id"'), '表单没有携带 visitor_id');
    truthy($page['likes'] === 0, '初始点赞数应为 0，实际 ' . $page['likes']);
    truthy($page['liked'] === false, '初始不该显示已赞');
});

check('点赞：303 回文章页，ledger 与回显同时生效；同主体重复提交幂等', function () use ($open, $submit, $cid, $cookieA): void {
    $page = $open($cookieA);
    $cookie = $cookieA;
    $like = ['slug' => 'pv-a', '_token' => $page['token'], 'visitor_id' => $page['visitor'],
        'comment_id' => (string) $cid, '_intent' => 'like', 'vote' => 'add'];

    $res = $submit($like, $cookie);
    truthy($res->status() === 303, '点赞成功应 303，实际 ' . $res->status());
    truthy(str_contains((string) ($res->headers()['Location'] ?? ''), '/post?slug=pv-a'), '303 目标不对');

    $seen = $open($cookie);
    truthy($seen['likes'] === 1, '点赞后台账应为 1，实际 ' . $seen['likes']);
    truthy($seen['liked'] === true, 'liked 没有回显为真（空头支票没修好）');

    // 同主体再点一次：幂等，数字不该翻倍。用新令牌，因为 令牌 是一次性的。
    $again = $submit(['_token' => $seen['token']] + $like, $cookie);
    truthy($again->status() === 303, '重复点赞应正常 303');
    $seen2 = $open($cookie);
    truthy($seen2['likes'] === 1, '重复点赞把数字加了两次：' . $seen2['likes']);
});

check('跨主体独立：第二个人点赞后总数变 2，第一个人的已赞状态不被改写', function () use ($open, $submit, $cid, $cookieA): void {
    $a = $open($cookieA);
    $b = $open();
    $cookieB = 'wx_visitor=' . rawurlencode($b['visitor']);
    truthy($a['visitor'] !== $b['visitor'], '两次访问拿到了同一个 visitor');
    truthy($a['liked'] === true, '第一个人应仍处于已赞');
    truthy($b['liked'] === false, '第二个人不该继承别人的已赞状态');
    truthy($b['likes'] === 1, '第二个人看到的应是同一个总数 1');

    $res = $submit(['slug' => 'pv-a', '_token' => $b['token'], 'visitor_id' => $b['visitor'],
        'comment_id' => (string) $cid, '_intent' => 'like', 'vote' => 'add'], $cookieB);
    truthy($res->status() === 303, '第二个人点赞应 303');

    $seenB = $open($cookieB);
    truthy($seenB['likes'] === 2, '两个人点赞后总数应为 2，实际 ' . $seenB['likes']);
    truthy($seenB['liked'] === true, '第二个人的已赞状态没回显');

    $seenA = $open($cookieA);
    truthy($seenA['liked'] === true, '第一个人的已赞状态被别人的操作改写了');
});

check('取消点赞：remove 后自己的赞消失，但别人的赞还在', function () use ($open, $submit, $cid, $cookieA): void {
    $page = $open($cookieA);
    $cookie = $cookieA;
    $res = $submit(['slug' => 'pv-a', '_token' => $page['token'], 'visitor_id' => $page['visitor'],
        'comment_id' => (string) $cid, '_intent' => 'like', 'vote' => 'remove'], $cookie);
    truthy($res->status() === 303, '取消点赞应 303，实际 ' . $res->status());

    $seen = $open($cookie);
    truthy($seen['liked'] === false, '取消后仍显示已赞');
    truthy($seen['likes'] === 1, '取消的是自己的赞，总数应回落到 1，实际 ' . $seen['likes']);
});

check('失败态：无令牌 / 未知意图 / 非法原因都留在 post 布局并给真实状态码', function () use ($open, $htmlKernel): void {
    // 令牌 是一次性令牌：每个用例都要重新取页面，否则第二个用例会先撞 403。
    $cases = [
        ['无 令牌', static fn(string $token): array => ['slug' => 'pv-a', 'comment_id' => '1', '_intent' => 'like'], 403],
        ['未知意图', static fn(string $token): array => ['slug' => 'pv-a', '_token' => $token,
            'comment_id' => '1', '_intent' => 'unknown'], 422],
        ['非法原因', static fn(string $token): array => ['slug' => 'pv-a', '_token' => $token,
            'comment_id' => '1', '_intent' => 'report', 'reason' => 'bogus'], 422],
    ];
    foreach ($cases as [$label, $make, $expect]) {
        $page = $open();
        $r = $htmlKernel->handle(new Request('POST', '/post', [], [], $make($page['token'])));
        truthy($r->status() === $expect, $label . ' 应 ' . $expect . '，实际 ' . $r->status());
        $html = $r->body();
        truthy(str_contains($html, 'data-layout="post"'), $label . ' 的失败态被甩离 post 布局');
        truthy(str_contains($html, 'data-state-phase="failed"'), $label . ' 没有显式失败态');
        truthy($r->status() !== 303, $label . ' 用 303 假装成功了');
    }
});

check('不可互动目标：对不存在的评论点赞被明确拒绝，不静默成功', function () use ($open, $htmlKernel): void {
    $page = $open();
    $r = $htmlKernel->handle(new Request('POST', '/post', [], [], [
        'slug' => 'pv-a', '_token' => $page['token'], 'comment_id' => '999999',
        '_intent' => 'like', 'vote' => 'add',
    ]));
    truthy($r->status() !== 303, '对不存在的评论点赞不该 303');
    truthy(in_array($r->status(), [404, 409, 422], true), '应给出 4xx 明确拒绝，实际 ' . $r->status());
    truthy(str_contains($r->body(), 'data-state-phase="failed"'), '缺少显式失败态');
});

dropTables($prefix);
Fs::removeDir($site);
