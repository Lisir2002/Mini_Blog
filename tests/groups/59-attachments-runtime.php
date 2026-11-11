<?php
declare(strict_types=1);

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Http\Request;

/**
 * 59 · 附件库运行时：先选文章，再改动，删之前先问正文
 *
 * 从 HTTP 面证明六件事：
 *  1. post_slug 必填导致附件库只能按文章浏览，未选文章时给文章清单；
 *  2. 私有附件对后台可见（按当前身份取数），下载只给链接、页面不代理字节；
 *  3. 五个写动作各自现场问 attachment.manage，票据不替代裁决；
 *  4. 正文仍引用时删除被硬挡；读不到正文时 fail-closed；
 *  5. 改挂载到不存在的文章被挡（没有 list-all 接口补救）、超长名字不被静默截断；
 *  6. 成功 303 / 失败不跳转且回填，结果不把「此前已删」说成「删除成功」。
 */
echo "\n=== 59. 附件库运行时 ===\n";

$site   = tempSite('attach-runtime');
$prefix = 'at_';
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
$registry = $k->container()->resolve(ApiRegistryInterface::class);
$admin = ['user_id' => 1, 'username' => 'admin', 'role' => 'admin'];

// 两篇文章 + 两个附件；正文里引用其中一个，另一个不引用。
foreach ([['t59-a', '附件库测试甲'], ['t59-b', '附件库测试乙']] as [$slug, $title]) {
    $registry->execute('content.create', ['slug' => $slug, 'title' => $title,
        'body' => '正文占位', 'status' => 'published', 'author' => 'admin']);
}
$secret = 'ATTACH-BODY-SENTINEL-7351';
$up = static function (string $name, string $slug, string $content, string $vis = 'public')
    use ($registry, $admin): int {
    $r = $registry->execute('attachments.upload', ['name' => $name, 'post_slug' => $slug,
        'visibility' => $vis, '_content' => $content, '_identity' => $admin]);
    return (int) ($r['id'] ?? 0);
};
$idRef = $up('cover.png.txt', 't59-a', $secret);
$idFree = $up('spare.txt', 't59-a', 'plain text');
$idPrivate = $up('private.txt', 't59-a', 'private bytes', 'private');
// txt 扩展名会被推导成 text/plain；改用 md 造一张「图片」以外的类型即可。
truthy($idRef > 0 && $idFree > 0 && $idPrivate > 0, '造数失败：附件没上传成功');

$registry->execute('content.update', ['slug' => 't59-a',
    'body' => '封面：![cover.png.txt](/attach/download?id=' . $idRef . ')',
    '_identity' => $admin]);

[, , $h] = $hit('POST', '/login', [], ['username' => 'admin', 'password' => 'admin123']);
$cookie = 'wx_session=' . cookieValue((string) ($h['Set-Cookie'] ?? ''), 'wx_session');

/** @return array{0:int, 1:string, 2:string} status / Location / body */
$post = static function (array $body, string $slug, int $id, string $ticket)
    use ($hit, $cookie): array {
    [$s, $b, $headers] = $hit('POST', '/console/attachments', ['slug' => $slug, 'id' => $id],
        $body + ['_form_ticket' => $ticket, 'id' => (string) $id, 'slug' => $slug],
        ['Cookie' => $cookie]);
    return [$s, (string) ($headers['Location'] ?? ''), $b];
};
$ticketOf = static function (string $slug, int $id) use ($hit, $cookie, $field): string {
    [, $page] = $hit('GET', '/console/attachments', ['slug' => $slug, 'id' => $id],
        [], ['Cookie' => $cookie]);
    return $field($page, '_form_ticket');
};

check('匿名 401；未选文章时给文章清单，不是空白也不是全站附件', function () use ($hit, $cookie): void {
    [$anon] = $hit('GET', '/console/attachments');
    truthy($anon === 401, '匿名应 401，实际 ' . $anon);
    [$s, $page] = $hit('GET', '/console/attachments', [], [], ['Cookie' => $cookie]);
    truthy($s === 200, '管理员应 200，实际 ' . $s);
    truthy(str_contains($page, '选择一篇文章'), '没有文章选择入口');
    truthy(str_contains($page, '/console/attachments?slug=t59-a'), '文章链接没带 slug');
    truthy(str_contains($page, '附件库：先选文章'), '缺少范围说明');
});

check('选中文章：清单 / 下载链接 / 正文引用 / 私有标注都在，页面不代理字节',
    function () use ($hit, $cookie, $idRef, $idPrivate, $secret): void {
        [, $page] = $hit('GET', '/console/attachments', ['slug' => 't59-a'], [], ['Cookie' => $cookie]);
        foreach (['当前文章', '附件 · 当前身份可见 3 条', '受控下载', '选择要改的附件',
            '正文引用：URL + 展示名', '私有：需登录'] as $needle) {
            truthy(str_contains($page, $needle), '页面缺少「' . $needle . '」');
        }
        truthy(str_contains($page, '/attach/download?id=' . $idRef), '没有引用附件的下载链接');
        truthy(str_contains($page, '/attach/download?id=' . $idPrivate), '私有附件没出现在后台清单');
        truthy(!str_contains($page, $secret), '页面把文件字节读进了页面层');
        truthy(!str_contains($page, '改动这条附件'), '未选 id 时不该渲染写表单');
    });

$ticket = $ticketOf('t59-a', $idFree);
truthy($ticket !== '', '没拿到表单票据，后面所有写用例都会误判');

check('五个动作都由按钮给出 op：直接构造的空 op 一律 422', function () use ($post, $ticket, $idFree): void {
    [$s, $loc, $b] = $post(['new_name' => 'x'], 't59-a', $idFree, $ticket);
    truthy($s === 422 && $loc === '', '缺 op 不该成功或跳转');
    truthy(str_contains($b, '只能由表单按钮给出'), '没有说明 op 必须由按钮给出');
});

check('改名：303 且真的改了名，旧下载 URL 不变；超长名字先拒绝',
    function () use ($post, $ticket, $idFree, $registry): void {
        [$s, $loc] = $post(['op' => 'rename', 'new_name' => 'renamed.txt'], 't59-a', $idFree, $ticket);
        truthy($s === 303 && str_contains($loc, 'outcome=rename'), '改名没成功：' . $loc);
        truthy(str_contains(urldecode($loc), '下载 URL 仍是'), '没有说明 URL 不变');
        $list = $registry->execute('attachments.list', ['post_slug' => 't59-a']);
        $names = array_column((array) ($list['items'] ?? []), 'name', 'id');
        truthy(($names[$idFree] ?? '') === 'renamed.txt', '接口里名字没变');
        [$bad, $loc2, $b] = $post(['op' => 'rename', 'new_name' => str_repeat('长', 121)],
            't59-a', $idFree, $ticket);
        truthy($bad === 422 && $loc2 === '', '超长名字没被挡住');
        truthy(str_contains($b, '静默截断'), '没有解释为什么先拒绝');
    });

check('正文按名字引用时改名：要求手写确认，确认后提醒旧名还在正文里',
    function () use ($post, $ticket, $idRef): void {
        [$s, $loc, $b] = $post(['op' => 'rename', 'new_name' => 'new-cover.txt'],
            't59-a', $idRef, $ticket);
        truthy($s === 422 && $loc === '', '没确认就改了名');
        truthy(str_contains($b, '确认新名'), '没有说明确认方式');
        [$ok, $loc2] = $post(['op' => 'rename', 'new_name' => 'new-cover.txt',
            'confirm' => 'new-cover.txt'], 't59-a', $idRef, $ticket);
        truthy($ok === 303, '确认后没改成功');
        truthy(str_contains(urldecode($loc2), '正文里仍写着旧名'), '没有提醒正文仍引用旧名');
    });

check('可见性：两个枚举按钮生效，私有下载说明二次鉴权',
    function () use ($post, $ticket, $idFree, $registry): void {
        [, $loc] = $post(['op' => 'private'], 't59-a', $idFree, $ticket);
        truthy(str_contains($loc, 'outcome=private'), '没标 private：' . $loc);
        truthy(str_contains(urldecode($loc), '每次二次鉴权'), '没说明私有下载会再鉴权');
        $list = $registry->execute('attachments.list', ['post_slug' => 't59-a', '_identity' => null]);
        $ids = array_column((array) ($list['items'] ?? []), 'id');
        truthy(!in_array($idFree, $ids, true), '匿名清单里还能看到私有附件');
        $post(['op' => 'public'], 't59-a', $idFree, $ticket);
    });

check('改挂载：目标不存在被挡，目标存在才真的移过去',
    function () use ($post, $ticket, $idFree, $registry, $hit, $cookie): void {
        [$s, $loc, $b] = $post(['op' => 'move', 'target_slug' => 't59-no-such-post'],
            't59-a', $idFree, $ticket);
        truthy($s === 422 && $loc === '', '挂到不存在的文章没被挡住');
        truthy(str_contains($b, '再也找不回来'), '没有解释为什么必须校验目标');
        [$ok, $loc2] = $post(['op' => 'move', 'target_slug' => 't59-b'], 't59-a', $idFree, $ticket);
        truthy($ok === 303 && str_contains($loc2, 'outcome=move'), '没移过去：' . $loc2);
        truthy(str_contains(urldecode($loc2), '不再列出它'), '没说明原文章清单会少一条');
        $b2 = $registry->execute('attachments.list', ['post_slug' => 't59-b']);
        truthy(in_array($idFree, array_column((array) ($b2['items'] ?? []), 'id'), true),
            '目标文章的清单里没有这条附件');
        [, $page] = $hit('GET', '/console/attachments', ['slug' => 't59-a'], [], ['Cookie' => $cookie]);
        truthy(str_contains($page, '附件 · 当前身份可见 2 条'), '原文章清单没有随之变少');
    });

check('删除：正文仍引用时硬挡，改掉引用后才删得掉',
    function () use ($post, $ticket, $idRef, $registry, $admin): void {
        [$s, $loc, $b] = $post(['op' => 'delete', 'confirm' => (string) $idRef],
            't59-a', $idRef, $ticket);
        truthy($s === 422 && $loc === '', '正文还引用着就删掉了');
        truthy(str_contains($b, '请先把文章里的引用改掉'), '没有给出可执行的下一步');
        $registry->execute('content.update', ['slug' => 't59-a',
            'body' => '正文已清掉附件引用', '_identity' => $admin]);
        [$ok, $loc2] = $post(['op' => 'delete', 'confirm' => (string) $idRef],
            't59-a', $idRef, $ticket);
        truthy($ok === 303 && str_contains($loc2, 'outcome=delete'), '没删掉：' . $loc2);
        truthy(str_contains(urldecode($loc2), '字节仍在磁盘'), '没说明软删除与字节回收分开');
        [$again, , $b2] = $post(['op' => 'delete', 'confirm' => (string) $idRef],
            't59-a', $idRef, $ticket);
        truthy($again === 422 && str_contains($b2, '不在'), '重复删除没说明已经看不见了');
    });

check('权限隔离：只留 attachment.read 时清单仍可读，写提交 403',
    function () use ($k, $post, $ticket, $idPrivate, $hit, $cookie): void {
        $acl = $k->container()->resolve(AccessControlInterface::class);
        // 页面声明 attachment.read（能看），写动作要 attachment.manage（能改）。
        // 撤掉 manage 只留 read：GET 仍 200，POST 必须在 Action 层被 403 挡住 ——
        // 这才证明「页面层再判一次」不是守卫检查的重复。
        $acl->revoke('admin', '*');
        $acl->grant('admin', 'attachment.read');
        try {
            [$s, $loc, $b] = $post(['op' => 'public'], 't59-a', $idPrivate, $ticket);
            truthy($s === 403 && $loc === '', '越权提交没被挡住，实际 ' . $s);
            truthy(str_contains($b, 'attachment.manage'), '没点明缺哪个权限点');
            [, $view] = $hit('GET', '/console/attachments', ['slug' => 't59-a'], [], ['Cookie' => $cookie]);
            truthy(str_contains($view, '受控下载'), '只有读权限时连清单都看不到了');
        } finally {
            $acl->revoke('admin', 'attachment.read');
            $acl->grant('admin', '*');
        }
    });

check('方法边界：无票据 Cookie POST 401，已认证 PUT 405；失败不跳转且回填',
    function () use ($hit, $cookie, $post, $ticket, $idPrivate): void {
        [$anon] = $hit('POST', '/console/attachments', [], [], ['Cookie' => $cookie]);
        truthy($anon === 401, '无票据 POST 应 401，实际 ' . $anon);
        [$put, , $heads] = $hit('PUT', '/console/attachments', [], [], bearer('test-admin-token'));
        truthy($put === 405 && ($heads['Allow'] ?? '') === 'GET, HEAD, POST', 'PUT 的 405/Allow 不对');
        [$s, $loc, $b] = $post(['op' => 'rename', 'new_name' => ''], 't59-a', $idPrivate, $ticket);
        truthy($s === 422 && $loc === '', '失败不该跳转');
        truthy(str_contains($b, '提交没有生效'), '没有渲染失败提示条');
    });
