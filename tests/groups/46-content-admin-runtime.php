<?php
declare(strict_types=1);

use Wx\Core\Http\Request;
use Wx\Systems\Lifecycle\Fs;

/**
 * 46 · 内容管理台：页面写表单的完整形态
 *
 * 这一组证明「站点能自己用」的最小闭环：
 *  1. 登录管理员 GET 能看到文章与表单，票据随页面下发；
 *  2. 没有票据的 POST 一律 401 —— 非 GET 不读 Cookie 的边界没松；
 *  3. 带票据的状态迁移 / 移入回收站 / 恢复 / 彻底删除都真的落到数据上；
 *  4. 权限裁剪：author 能进页面，但看不到发布 / 删除按钮；
 *  5. 失败绝不 303：非法迁移、错误二次确认都留在页面上说明原因。
 */
echo "\n=== 46. 内容管理台：列表 + 生命周期 + 权限裁剪 ===\n";

$site = tempSite('content-admin');
$prefix = 'ic_';
dropTables($prefix);
$k = isolatedKernel($site, $prefix, ['theme' => ['active' => 'glass'], 'api' => ['expose_write' => true,
    'tokens' => ['t-author-token' => ['role' => 'author', 'username' => 'author', 'user_id' => 9]]]]);
$k->boot();
$reg = $k->container()->resolve(Wx\Contracts\ApiRegistryInterface::class);
$browser = ['Cookie' => 'wx_session=test-admin-token'];

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

check('匿名：401 需要登录，不泄漏文章列表', function () use ($hit): void {
    [$s, $b] = $hit('/console/content');
    truthy($s === 401, '匿名应 401，实际 ' . $s);
    truthy(str_contains($b, '需要登录'), '拒绝页没有说明需要登录');
});

check('管理员：200，列表与票据同在，回收站视图有独立空态', function () use ($hit, $browser): void {
    [$s, $b] = $hit('/console/content', [], 'GET', [], $browser);
    truthy($s === 200, '管理员 GET 应 200，实际 ' . $s);
    truthy(str_contains($b, '_form_ticket'), '页面没有下发票据');
    truthy(str_contains($b, 'slug：'), '列表没有列出文章');

    [$ts, $tb] = $hit('/console/content', ['view' => 'trash'], 'GET', [], $browser);
    truthy($ts === 200, '回收站视图应 200，实际 ' . $ts);
    truthy(str_contains($tb, '回收站是空的'), '回收站空态与列表空态混用了');
});

check('无票据提交：401 表单已失效', function () use ($hit, $browser): void {
    [$s, $b] = $hit('/console/content', [], 'POST',
        ['slug' => 'second-post', 'op' => 'draft'], $browser);
    truthy($s === 401, '无票据应 401，实际 ' . $s);
    truthy(str_contains($b, '表单已失效'), '没有说明是票据问题');
});

check('状态迁移：303 且状态真的变了（不是只改了页面）', function () use ($hit, $browser, $reg, $field): void {
    [$g, $b] = $hit('/console/content', [], 'GET', [], $browser);
    $ticket = $field($b, '_form_ticket');
    truthy($ticket !== '', 'GET 没有下发票据');

    [$s, , $h] = $hit('/console/content', [], 'POST',
        ['slug' => 'second-post', 'op' => 'draft', '_form_ticket' => $ticket], $browser);
    truthy($s === 303, '迁移应 303，实际 ' . $s);
    truthy(($h['Location'] ?? '') === '/console/content', '303 没有回到列表');

    // 用管理员身份复核：草稿对匿名不可见，这不是 bug，而是内容可见性在做它的事。
    $post = (array) $reg->execute('content.post', ['slug' => 'second-post',
        '_identity' => ['user_id' => 1, 'username' => 'admin', 'role' => 'admin']]);
    truthy(($post['status'] ?? '') === 'draft', '状态没有真的变成草稿：' . ($post['status'] ?? '?'));
});

check('未知动作：422 并列出合法动作，不落成某个默认操作', function () use ($hit, $browser, $field): void {
    [$g, $b] = $hit('/console/content', [], 'GET', [], $browser);
    $ticket = $field($b, '_form_ticket');
    [$s, $body] = $hit('/console/content', [], 'POST',
        ['slug' => 'second-post', 'op' => 'review', '_form_ticket' => $ticket], $browser);
    truthy($s === 422, '未知动作应 422，实际 ' . $s);
    truthy(str_contains($body, 'publish'), '错误信息没有列出合法动作');
});

check('移入回收站 → 恢复：两步都落库，恢复不会顺手发布', function () use ($hit, $browser, $reg, $field): void {
    [$g, $b] = $hit('/console/content', [], 'GET', [], $browser);
    $ticket = $field($b, '_form_ticket');
    [$s] = $hit('/console/content', [], 'POST',
        ['slug' => 'second-post', 'op' => 'trash', '_form_ticket' => $ticket], $browser);
    truthy($s === 303, '移入回收站应 303，实际 ' . $s);

    [$ts, $tb] = $hit('/console/content', ['view' => 'trash'], 'GET', [], $browser);
    truthy(str_contains($tb, 'second-post'), '回收站里看不到刚删的文章');
    truthy(str_contains($tb, '恢复'), '回收站没有恢复入口');

    [$rs, , $rh] = $hit('/console/content', [], 'POST',
        ['slug' => 'second-post', 'op' => 'restore', '_form_ticket' => $ticket], $browser);
    truthy($rs === 303, '恢复应 303，实际 ' . $rs);
    truthy(($rh['Location'] ?? '') === '/console/content?view=trash', '恢复后没有回到回收站视图');
    $post = (array) $reg->execute('content.post', ['slug' => 'second-post',
        '_identity' => ['user_id' => 1, 'username' => 'admin', 'role' => 'admin']]);
    truthy(($post['status'] ?? '') !== 'published', '恢复把草稿变成了已发布（偷偷上线）');
});

check('彻底删除：确认串不符 422；写对才真正消失', function () use ($hit, $browser, $reg, $field): void {
    [$g, $b] = $hit('/console/content', [], 'GET', [], $browser);
    $ticket = $field($b, '_form_ticket');
    $hit('/console/content', [], 'POST',
        ['slug' => 'second-post', 'op' => 'trash', '_form_ticket' => $ticket], $browser);

    [$s1, $b1] = $hit('/console/content', [], 'POST',
        ['slug' => 'second-post', 'op' => 'purge', 'confirm' => 'wrong', '_form_ticket' => $ticket], $browser);
    truthy($s1 === 422, '错误确认串应 422，实际 ' . $s1);
    truthy(str_contains($b1, 'confirm'), '没有说明确认串不一致');

    [$s2] = $hit('/console/content', [], 'POST',
        ['slug' => 'second-post', 'op' => 'purge', 'confirm' => 'second-post', '_form_ticket' => $ticket], $browser);
    truthy($s2 === 303, '正确确认串应 303，实际 ' . $s2);

    $stillThere = true;
    try {
        $reg->execute('content.post', ['slug' => 'second-post',
            '_identity' => ['user_id' => 1, 'username' => 'admin', 'role' => 'admin']]);
    } catch (\Throwable) {
        $stillThere = false;
    }
    truthy($stillThere === false, '彻底删除后文章仍然可读');
});

check('跨页挪用：内容票据提交到审核队列必须被拒', function () use ($hit, $browser, $field): void {
    [$g, $b] = $hit('/console/content', [], 'GET', [], $browser);
    $ticket = $field($b, '_form_ticket');
    [$s] = $hit('/console/queue', [], 'POST',
        ['id' => '1', 'to' => 'published', '_form_ticket' => $ticket], $browser);
    truthy($s === 401, '跨页票据未被拒绝，实际 ' . $s);
});

check('权限裁剪：author 能进页面，但看不到发布 / 删除按钮', function () use ($hit): void {
    $author = ['Cookie' => 'wx_session=t-author-token'];
    [$s, $b] = $hit('/console/content', [], 'GET', [], $author);
    truthy($s === 200, 'author 应可进入内容页（有 content.read），实际 ' . $s);
    foreach (['移入回收站', 'value="publish"'] as $needle) {
        truthy(!str_contains($b, $needle), 'author 看到了无权执行的按钮：' . $needle);
    }
    truthy(str_contains($b, '没有本行的可用动作'), '没有说明为什么没有按钮');
});

dropTables($prefix);
Fs::removeDir($site);
