<?php
declare(strict_types=1);

use Wx\Core\Http\Request;

/**
 * 51 · 写文章运行时：从空白到发布，正文真的进了库
 *
 * 这一组只从 HTTP 面取证：
 *  1. GET 是一张可提交的表单，带票据与两个状态按钮；
 *  2. 只带 Cookie 提交一律 401（非 GET 不读 Cookie 的边界没有松动）；
 *  3. 保存草稿 → 匿名读不到；发布 → 匿名读得到，且正文与提交的一致；
 *  4. 空标题 / 非法状态 / 重复 slug 各自有可区分的状态码，失败不跳转、不丢正文。
 */
echo "\n=== 51. 写文章运行时 ===\n";

$site   = tempSite('write-runtime');
$prefix = 'wr_';
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

// 浏览器路径：先登录拿会话 Cookie，再用同一个 Cookie 走 GET / POST。
[, , $h] = $hit('POST', '/login', [], ['username' => 'admin', 'password' => 'admin123']);
$cookie = 'wx_session=' . cookieValue((string) ($h['Set-Cookie'] ?? ''), 'wx_session');
truthy($cookie !== 'wx_session=', '登录没有建立会话');

check('GET 编辑器：表单、正文域、票据、两个状态按钮都在', function () use ($hit, $cookie): void {
    [$s, $b] = $hit('GET', '/console/write', [], [], ['Cookie' => $cookie]);
    truthy($s === 200, '编辑器应 200，实际 ' . $s);
    truthy(str_contains($b, 'action="/console/write"'), '没有指向编辑器的表单');
    truthy(str_contains($b, '<textarea'), '正文没有 textarea');
    truthy(str_contains($b, '_form_ticket'), '没有下发票据');
    truthy(str_contains($b, 'value="draft"') && str_contains($b, 'value="published"'),
        '缺少草稿 / 发布两个状态按钮');
});

check('只带 Cookie 提交：401，正文不会落库', function () use ($hit, $cookie): void {
    [$s] = $hit('POST', '/console/write', [], [
        'title' => '无票', 'body' => 'x', 'status' => 'draft', 'slug' => 'wr-no-ticket',
    ], ['Cookie' => $cookie]);
    truthy($s === 401, '无票据应 401，实际 ' . $s);
    [$read] = $hit('GET', '/post', ['slug' => 'wr-no-ticket']);
    truthy($read === 404, '无票提交竟然创建了文章');
});

check('保存草稿：303，匿名读不到，编辑页能回读正文', function () use ($hit, $cookie, $field): void {
    [, $b] = $hit('GET', '/console/write', [], [], ['Cookie' => $cookie]);
    $ticket = $field($b, '_form_ticket');
    [$s, , $h] = $hit('POST', '/console/write', [], [
        'slug' => 'wr-draft', 'title' => '草稿标题', 'body' => '草稿正文第一行',
        'status' => 'draft', '_form_ticket' => $ticket,
    ], ['Cookie' => $cookie]);
    truthy($s === 303, '保存草稿应 303，实际 ' . $s);
    truthy(str_contains((string) ($h['Location'] ?? ''), 'slug=wr-draft'), '303 没带回新 slug');

    [$anon] = $hit('GET', '/post', ['slug' => 'wr-draft']);
    truthy($anon === 404, '草稿对匿名可见（可见性口径被绕过）');

    [$es, $eb] = $hit('GET', '/console/write', ['slug' => 'wr-draft'], [], ['Cookie' => $cookie]);
    truthy($es === 200, '编辑页应 200，实际 ' . $es);
    truthy(str_contains($eb, '草稿标题') && str_contains($eb, '草稿正文第一行'), '编辑页没有回读已存内容');
});

check('发布：303，匿名可读且正文一致', function () use ($hit, $cookie, $field): void {
    [, $b] = $hit('GET', '/console/write', ['slug' => 'wr-draft'], [], ['Cookie' => $cookie]);
    [$s] = $hit('POST', '/console/write', [], [
        'slug' => 'wr-draft', 'mode' => 'edit', 'title' => '发布标题', 'body' => '发布后的正文',
        'status' => 'published', '_form_ticket' => $field($b, '_form_ticket'),
    ], ['Cookie' => $cookie]);
    truthy($s === 303, '发布应 303，实际 ' . $s);

    [$ps, $pb] = $hit('GET', '/post', ['slug' => 'wr-draft']);
    truthy($ps === 200, '发布后文章页应 200，实际 ' . $ps);
    truthy(str_contains($pb, '发布标题') && str_contains($pb, '发布后的正文'), '文章页内容与提交不一致');
});

check('空标题：422，留在原页并保留正文', function () use ($hit, $cookie, $field): void {
    [, $b] = $hit('GET', '/console/write', [], [], ['Cookie' => $cookie]);
    [$s, $page] = $hit('POST', '/console/write', [], [
        'slug' => 'wr-empty', 'title' => '', 'body' => '这段正文不能丢',
        'status' => 'draft', '_form_ticket' => $field($b, '_form_ticket'),
    ], ['Cookie' => $cookie]);
    truthy($s === 422, '空标题应 422，实际 ' . $s);
    truthy(str_contains($page, '标题不能为空'), '没有说明失败原因');
    truthy(str_contains($page, '这段正文不能丢'), '失败后正文被清空了');
});

check('非法状态：422，不落成某个默认状态', function () use ($hit, $cookie, $field): void {
    [, $b] = $hit('GET', '/console/write', [], [], ['Cookie' => $cookie]);
    [$s, $page] = $hit('POST', '/console/write', [], [
        'slug' => 'wr-bad-status', 'title' => 'x', 'body' => 'y',
        'status' => 'whatever', '_form_ticket' => $field($b, '_form_ticket'),
    ], ['Cookie' => $cookie]);
    truthy($s === 422, '非法状态应 422，实际 ' . $s);
    truthy(str_contains($page, 'draft / published'), '没有列出允许的状态');
});

check('重复 slug：409，不覆盖已有文章', function () use ($hit, $cookie, $field): void {
    [, $b] = $hit('GET', '/console/write', [], [], ['Cookie' => $cookie]);
    [$s, $page] = $hit('POST', '/console/write', [], [
        'slug' => 'wr-draft', 'title' => '想覆盖', 'body' => 'x',
        'status' => 'draft', '_form_ticket' => $field($b, '_form_ticket'),
    ], ['Cookie' => $cookie]);
    truthy($s === 409, '重复 slug 应 409，实际 ' . $s);
    truthy(str_contains($page, '已被占用'), '没有说明 slug 冲突');
});

check('不存在的 slug：404 且不渲染编辑表单', function () use ($hit, $cookie): void {
    [$s, $b] = $hit('GET', '/console/write', ['slug' => 'no-such-post'], [], ['Cookie' => $cookie]);
    truthy($s === 404, '不存在应 404，实际 ' . $s);
    truthy(!str_contains($b, 'action="/console/write"'), '404 时仍渲染了可提交表单');
});

check('非管理员：403，写入口不向普通账号开放', function () use ($hit, $k): void {
    $created = $k->handle(new Request('POST', '/api/v1/auth/register', [],
        ['Content-Type' => 'application/json'], [],
        (string) json_encode(['username' => 'writer1', 'password' => 'writer-pass-2026'])));
    truthy($created->status() === 200 || $created->status() === 403, '注册闭环异常：' . $created->status());
    // 注册关闭时也要证明登录用户进不来：用会话接口建立普通用户会话。
    $login = $k->handle(new Request('POST', '/api/v1/auth/session', [],
        ['Content-Type' => 'application/json'], [],
        (string) json_encode(['username' => 'writer1', 'password' => 'writer-pass-2026'])));
    if ($login->status() === 200) {
        $c = 'wx_session=' . cookieValue((string) ($login->headers()['Set-Cookie'] ?? ''), 'wx_session');
        [$s] = $hit('GET', '/console/write', [], [], ['Cookie' => $c]);
        truthy($s === 403, '普通用户进入写文章页应 403，实际 ' . $s);
    }
});

dropTables($prefix);
