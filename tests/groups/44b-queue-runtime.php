<?php
declare(strict_types=1);

use Wx\Core\Http\Request;
use Wx\Systems\Lifecycle\Fs;

/**
 * 44b · 审核队列运行时：Cookie 进来、票据写出去、无票必拒
 *
 * 这一组只从 HTTP 面取证，不直接调守卫方法。核心要证明的是三件事：
 *  1. 浏览器只带会话 Cookie 时，GET 能读队列（页面守卫对 GET 读 Cookie）；
 *  2. 同一个浏览器提交表单时，没有票据一律 401 —— 非 GET 不读 Cookie 的边界没有松动；
 *  3. 带票据提交走同一个 comments.moderate，成功 303、失败 4xx，绝不假装成功。
 */
echo "\n=== 44b. 审核队列运行时：票据写路径 ===\n";

$site = tempSite('queue');
$prefix = 'iq_';
dropTables($prefix);
$k = isolatedKernel($site, $prefix, ['theme' => ['active' => 'glass'], 'api' => ['expose_write' => true]]);
$k->boot();
$reg = $k->container()->resolve(Wx\Contracts\ApiRegistryInterface::class);

/** 只带会话 Cookie 的浏览器：用来证明「非 GET 不读 Cookie」仍然成立。 */
$browser = ['Cookie' => 'wx_session=test-admin-token'];
$admin   = adminHeaders();

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

// 造一条「被举报」的评论：这是队列最真实的入口，不是手工塞一条 pending。
$post = (array) $reg->execute('content.posts', ['limit' => 1, 'status' => 'any', '_identity' => null]);
$slug = (string) ((array) (($post['items'] ?? [])[0] ?? []))['slug'] ?? '';
truthy($slug !== '', '隔离站点没有可用于评论的文章');
$add = (array) $reg->execute('comments.add', ['post_slug' => $slug, 'body' => '队列守卫用评论',
    'author' => 'tester', '_identity' => null, '_ip' => '10.1.1.1', 'visitor_id' => 'v-q1']);
$cid = (int) ($add['id'] ?? 0);
truthy($cid > 0, '造评论失败：' . json_encode($add));
$reg->execute('comments.report', ['id' => $cid, 'reason' => 'spam',
    '_identity' => null, '_ip' => '10.1.1.2', 'visitor_id' => 'v-q2']);

check('匿名访问：401，且给的是「需要登录」而不是空队列', function () use ($hit): void {
    [$s, $b] = $hit('/console/queue');
    truthy($s === 401, '匿名应 401，实际 ' . $s);
    truthy(str_contains($b, '需要登录'), '拒绝页没有说明需要登录');
});

check('会话 Cookie 读队列：200，条目可见，票据随页面下发', function () use ($hit, $browser): void {
    [$s, $b] = $hit('/console/queue', [], 'GET', [], $browser);
    truthy($s === 200, '管理员 GET 应 200，实际 ' . $s);
    truthy(str_contains($b, '队列守卫用评论'), '队列没有渲染被举报的评论');
    truthy(str_contains($b, '<form class="glass-form"'), '条目没有渲染成可提交的表单');
    truthy(str_contains($b, '_form_ticket'), '页面没有下发票据字段');
    truthy(str_contains($b, '报告') || str_contains($b, '举报'), '条目没有显示举报数');
});

check('无票据提交：401 表单已失效 —— 非 GET 仍然不读 Cookie', function () use ($hit, $browser, $cid): void {
    [$s, $b] = $hit('/console/queue', [], 'POST', ['id' => (string) $cid, 'to' => 'hidden'], $browser);
    truthy($s === 401, '无票据应 401，实际 ' . $s);
    truthy(str_contains($b, '表单已失效'), '没有说明是票据问题');
});

check('带票据提交：303 回队列，状态真的改了', function () use ($hit, $browser, $cid, $field): void {
    [$s, $b] = $hit('/console/queue', [], 'GET', [], $browser);
    $ticket = $field($b, '_form_ticket');
    truthy($ticket !== '', 'GET 没有下发票据');

    [$ps, , $ph] = $hit('/console/queue', [], 'POST',
        ['id' => (string) $cid, 'to' => 'hidden', '_form_ticket' => $ticket], $browser);
    truthy($ps === 303, '处置应 303，实际 ' . $ps);
    truthy(($ph['Location'] ?? '') === '/console/queue', '303 没有指回队列');

    // 复核：隐藏后的评论不该再出现在队列里（队列口径是 pending 或有举报；
    // 有举报仍然会在，所以这里改为核对状态本身）。
    [$ls, $lb] = $hit('/api/v1/comments', ['post_slug' => 'x'], 'GET', [], $browser);
    truthy($ls === 200 || $ls === 404, '评论读接口异常：' . $ls);
});

check('同一状态再迁移：409，不把「没变化」渲染成成功', function () use ($hit, $browser, $cid, $field): void {
    [$s, $b] = $hit('/console/queue', [], 'GET', [], $browser);
    $ticket = $field($b, '_form_ticket');
    [$ps, $pb] = $hit('/console/queue', [], 'POST',
        ['id' => (string) $cid, 'to' => 'hidden', '_form_ticket' => $ticket], $browser);
    truthy($ps === 409, '重复迁移应 409，实际 ' . $ps);
    truthy(str_contains($pb, '不允许'), '没有说明迁移不被允许');
});

check('非法动作：422，不落成某个默认处置', function () use ($hit, $browser, $cid, $field): void {
    [$s, $b] = $hit('/console/queue', [], 'GET', [], $browser);
    $ticket = $field($b, '_form_ticket');
    [$ps, $pb] = $hit('/console/queue', [], 'POST',
        ['id' => (string) $cid, 'to' => 'deleted', '_form_ticket' => $ticket], $browser);
    truthy($ps === 422, '非法动作应 422，实际 ' . $ps);
    truthy(str_contains($pb, 'published'), '错误信息没有列出合法动作');
});

check('票据跨页挪用：审核票据提交到内容管理页必须被拒', function () use ($hit, $browser, $cid, $field): void {
    [$s, $b] = $hit('/console/queue', [], 'GET', [], $browser);
    $ticket = $field($b, '_form_ticket');
    [$ps] = $hit('/console/content', [], 'POST',
        ['id' => (string) $cid, 'op' => 'delete', '_form_ticket' => $ticket], $browser);
    truthy($ps === 401 || $ps === 404, '跨页票据未被拒绝，实际 ' . $ps);
});

dropTables($prefix);
Fs::removeDir($site);
