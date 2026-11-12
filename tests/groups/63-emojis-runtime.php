<?php
declare(strict_types=1);

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Http\Request;

/**
 * 63 · 表情目录运行时：内置不可删，自定义只认本站附件
 *
 * 从 HTTP 面证明七件事：
 *  1. 内置表情 custom=false 不给删除按钮，目录为空 / 读失败分开表达；
 *  2. 登记在页面层挡住非法短码与重复占用（40901 不复用），不把错甩给接口；
 *  3. 附件 id 必须正整数；附件不存在 40403 按「登记」语境翻译，不说成表情不存在；
 *  4. 删除要求手写短码确认，内置与不存在的短码都在页面层挡住；
 *  5. 删除不重写历史：回显明说正文存原文、渲染时解析，读者看到 :code: 字面量；
 *  6. 写动作现场问 emoji.manage，能看目录不等于能改表情表；
 *  7. 成功 303 / 失败不跳转且回填，方法边界成立。
 */
echo "\n=== 63. 表情目录与自定义表情 ===\n";

$site   = tempSite('emojis-runtime');
$prefix = 'e63_';
dropTables($prefix);
$k = isolatedKernel($site, $prefix, [
    'theme' => ['active' => 'glass'],
    'permission' => ['cache' => ['driver' => 'memory']],
]);
$k->boot();

$hit = static function (string $method, string $path, array $q = [], array $body = [],
    array $headers = []) use ($k): array {
    $res = $k->handle(new Request($method, $path, $q, $headers, $body));
    return [$res->status(), $res->body(), $res->headers()];
};
$field = static function (string $html, string $name): string {
    return preg_match('/name="' . preg_quote($name, '/') . '"[^>]*value="([^"]*)"/', $html, $m)
        ? (string) $m[1] : '';
};
$registry = $k->container()->resolve(ApiRegistryInterface::class);
$admin = ['user_id' => 1, 'username' => 'admin', 'role' => 'admin'];
$registry->execute('content.create', ['slug' => 'e63-a', 'title' => '甲',
    'body' => '正文', 'status' => 'published', 'author' => 'admin']);
$png = "\x89PNG\r\n\x1a\n" . str_repeat("\0", 32);
$attId = (int) $registry->execute('attachments.upload', ['name' => 'logo.png',
    'post_slug' => 'e63-a', 'visibility' => 'public', '_content' => $png,
    '_identity' => $admin])['id'];
truthy($attId > 0, '造数失败：图片附件没上传成功');

[, , $h] = $hit('POST', '/login', [], ['username' => 'admin', 'password' => 'admin123']);
$cookie = 'wx_session=' . cookieValue((string) ($h['Set-Cookie'] ?? ''), 'wx_session');
/** @return array{0:int, 1:string, 2:string} status / Location / body */
$post = static function (array $body, array $q, string $ticket) use ($hit, $cookie): array {
    [$s, $b, $headers] = $hit('POST', '/console/emojis', $q,
        $body + ['_form_ticket' => $ticket], ['Cookie' => $cookie]);
    return [$s, (string) ($headers['Location'] ?? ''), $b];
};
$ticketOf = static function (array $q) use ($hit, $cookie, $field): string {
    [, $page] = $hit('GET', '/console/emojis', $q, [], ['Cookie' => $cookie]);
    return $field($page, '_form_ticket');
};
$follow = static function (string $loc) use ($hit, $cookie): array {
    $q = []; parse_str((string) parse_url($loc, PHP_URL_QUERY), $q);
    [$s, $b] = $hit('GET', '/console/emojis', $q, [], ['Cookie' => $cookie]);
    return [$s, $b];
};
$ticket = $ticketOf([]);
truthy($ticket !== '', '没拿到表单票据，后面所有写用例都会误判');

check('匿名 401；默认页列出内置目录、类型筛选与登记表单，内置不给删除按钮',
    function () use ($hit, $cookie): void {
        [$anon] = $hit('GET', '/console/emojis');
        truthy($anon === 401, '匿名应 401，实际 ' . $anon);
        [$s, $page] = $hit('GET', '/console/emojis', [], [], ['Cookie' => $cookie]);
        truthy($s === 200, '管理员应 200，实际 ' . $s);
        foreach (['内置（不可删）', 'name="op" value="register"', '按类型筛选',
            '不接受外链', 'emoji.manage'] as $needle) {
            truthy(str_contains($page, $needle), '缺少「' . $needle . '」');
        }
        truthy(!str_contains($page, 'name="op" value="delete"'), '内置表情不该有删除按钮');
    });

check('类型筛选只改展示，不缩掉短码占用预查所需的全量目录',
    function () use ($hit, $cookie): void {
        [, $page] = $hit('GET', '/console/emojis', ['kind' => 'image'], [], ['Cookie' => $cookie]);
        truthy(str_contains($page, '共 ') && str_contains($page, '条内置'), '筛选页没给出计数');
        truthy(str_contains($page, 'name="op" value="register"'), '筛选页丢了登记表单');
    });

check('非法短码与正整数附件 id 在页面层挡住，422 不跳转且回填',
    function () use ($post, $ticket, $attId): void {
        [$s, $loc, $b] = $post(['op' => 'register', 'code' => 'BAD!',
            'attachment_id' => (string) $attId], [], $ticket);
        truthy($s === 422 && $loc === '', '非法短码应 422，实际 ' . $s);
        truthy(str_contains($b, '2–32 位'), '没说明短码规则');
        truthy(str_contains($b, 'value="BAD!"'), '失败没回填短码');
        [$s2, $loc2, $b2] = $post(['op' => 'register', 'code' => 'okcode',
            'attachment_id' => '0'], [], $ticket);
        truthy($s2 === 422 && $loc2 === '', 'attachment_id=0 应被挡住');
        truthy(str_contains($b2, '本站已上传的图片附件 id'), '没解释外链风险');
    });

check('附件不存在 40403 按「登记」语境翻译，不误说成表情不存在',
    function () use ($post, $ticket): void {
        [$s, $loc, $b] = $post(['op' => 'register', 'code' => 'nocode',
            'attachment_id' => '99999'], [], $ticket);
        truthy($s !== 303 && $loc === '', '附件不存在不该成功');
        truthy(str_contains($b, '引用的附件不存在'), '40403 没按登记语境翻译');
        truthy(!str_contains($b, '自定义表情不存在'), '把附件缺失说成表情缺失');
    });

check('登记成功 303，回显「已登记」并给删除表单与附件预览链接',
    function () use ($post, $ticket, $follow, $attId, $registry): void {
        [$s, $loc] = $post(['op' => 'register', 'code' => 'shipit',
            'attachment_id' => (string) $attId], [], $ticket);
        truthy($s === 303 && str_contains($loc, 'outcome=created'), '成功应 303 带 created，实际 ' . $s);
        [, $page] = $follow($loc);
        foreach (['已登记自定义表情', 'name="op" value="delete"',
            '/attach/download?id=' . $attId, '自定义（可删）'] as $needle) {
            truthy(str_contains($page, $needle), '缺少「' . $needle . '」');
        }
        $list = $registry->execute('emojis.list', []);
        $custom = array_values(array_filter($list['items'],
            static fn(array $e): bool => (bool) ($e['custom'] ?? false)));
        truthy(count($custom) === 1 && (string) $custom[0]['code'] === 'shipit', '自定义表情没落库');
    });

check('重复短码在页面层挡住（40901 不复用），不把占用甩给接口',
    function () use ($post, $ticketOf, $attId): void {
        $t = $ticketOf([]);
        [$s, $loc, $b] = $post(['op' => 'register', 'code' => 'shipit',
            'attachment_id' => (string) $attId], [], $t);
        truthy($s === 422 && $loc === '', '重复短码应 422，实际 ' . $s);
        truthy(str_contains($b, '已被内置表情或已登记表情占用'), '没说明占用原因');
        [$s2, , $b2] = $post(['op' => 'register', 'code' => 'smile',
            'attachment_id' => (string) $attId], [], $t);
        truthy($s2 === 422 && str_contains($b2, '已被内置表情或已登记表情占用'),
            '内置短码占用没被预先挡住');
    });

check('删除内置 / 缺确认 / 不存在的短码都被页面层挡住，各自说清原因',
    function () use ($post, $ticketOf): void {
        $t = $ticketOf([]);
        [$s1, , $b1] = $post(['op' => 'delete', 'code' => 'smile', 'confirm' => 'smile'], [], $t);
        truthy($s1 === 422 && str_contains($b1, '内置表情'), '内置删除没被挡住');
        [$s2, , $b2] = $post(['op' => 'delete', 'code' => 'shipit'], [], $t);
        truthy($s2 === 422 && str_contains($b2, '手写短码'), '缺确认没被挡住');
        [$s3, , $b3] = $post(['op' => 'delete', 'code' => 'nosuch', 'confirm' => 'nosuch'], [], $t);
        truthy($s3 === 422 && str_contains($b3, '不存在'), '不存在的短码没被挡住');
    });

check('删除成功 303，回显「已删除」并说明不重写历史、读者看到字面量',
    function () use ($post, $ticketOf, $follow, $registry): void {
        $t = $ticketOf([]);
        [$s, $loc] = $post(['op' => 'delete', 'code' => 'shipit', 'confirm' => 'shipit'], [], $t);
        truthy($s === 303 && str_contains($loc, 'outcome=deleted'), '成功应 303 带 deleted，实际 ' . $s);
        [, $page] = $follow($loc);
        foreach (['已删除自定义表情', '不重写历史', '字面量'] as $needle) {
            truthy(str_contains($page, $needle), '缺少「' . $needle . '」');
        }
        $list = $registry->execute('emojis.list', []);
        $custom = array_values(array_filter($list['items'],
            static fn(array $e): bool => (bool) ($e['custom'] ?? false)));
        truthy($custom === [], '自定义表情没被删掉');
    });

check('权限隔离：只留 content.read 时目录仍可读，写提交 403 且点明 emoji.manage',
    function () use ($k, $post, $ticket, $hit, $cookie, $attId): void {
        $acl = $k->container()->resolve(AccessControlInterface::class);
        // 票据绑 content.read（页面级），验票不重查 ACL；403 必须由 Action 现场裁决给出。
        $acl->revoke('admin', '*');
        $acl->grant('admin', 'content.read');
        try {
            [$s, $loc, $b] = $post(['op' => 'register', 'code' => 'zzz',
                'attachment_id' => (string) $attId], [], $ticket);
            truthy($s === 403 && $loc === '', '越权登记没被挡住，实际 ' . $s);
            truthy(str_contains($b, 'emoji.manage'), '没点明缺哪个权限点');
            [, $view] = $hit('GET', '/console/emojis', [], [], ['Cookie' => $cookie]);
            truthy(str_contains($view, '内置（不可删）'), '只有读权限时连目录都看不到了');
        } finally {
            $acl->revoke('admin', 'content.read');
            $acl->grant('admin', '*');
        }
    });

check('方法边界：无票据 Cookie POST 401，已认证 PUT 405；失败不跳转',
    function () use ($hit, $cookie, $post, $ticketOf, $attId): void {
        [$anon] = $hit('POST', '/console/emojis', [], [], ['Cookie' => $cookie]);
        truthy($anon === 401, '无票据 POST 应 401，实际 ' . $anon);
        [$put, , $heads] = $hit('PUT', '/console/emojis', [], [], bearer('test-admin-token'));
        truthy($put === 405 && ($heads['Allow'] ?? '') === 'GET, HEAD, POST', 'PUT 的 405/Allow 不对');
        [$s, $loc, $b] = $post(['op' => 'register', 'code' => 'BAD!',
            'attachment_id' => (string) $attId], [], $ticketOf([]));
        truthy($s === 422 && $loc === '', '失败不该跳转');
        truthy(str_contains($b, '提交没有生效'), '没有渲染失败提示条');
    });
