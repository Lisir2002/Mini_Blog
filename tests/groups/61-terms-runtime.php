<?php
declare(strict_types=1);

use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Http\Request;

/**
 * 61 · 分类与标签运行时：先看清现状，再整体替换
 *
 * 从 HTTP 面证明六件事（边界与权限另见 61b，同一隔离站点）：
 *  1. 空态 / 不可用 / 读失败是三种状态，不会都塌成「没有分类」；
 *  2. 术语新建 / 改名 / 删除接上 content.terms.manage，重复 slug 明说 40911 不复用；
 *  3. 文章挂载接上 content.attach，提交的是完整集合而不是增量；
 *  4. 引用不存在 / 写法错 / 超长文本在页面层挡住，不把 40404 甩给接口；
 *  5. 清空与删除都要手写确认，回报真正清理了几条关系；
 *  6. 成功 303 / 失败不跳转且回填、updated=false 与整体替换幂等都单独回报。
 */
echo "\n=== 61. 分类与标签运行时 ===\n";

$site   = tempSite('terms-runtime');
$prefix = 'tr_';
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
    // 主题渲染普通 input 时 name 与 value 之间隔着 type/placeholder，
    // hidden 才是相邻；用 [^>]* 兼容两种，仍不跨出标签。
    return preg_match('/name="' . preg_quote($name, '/') . '"[^>]*value="([^"]*)"/', $html, $m)
        ? (string) $m[1] : '';
};
$registry = $k->container()->resolve(ApiRegistryInterface::class);
$admin = ['user_id' => 1, 'username' => 'admin', 'role' => 'admin'];
foreach ([['t61-a', '术语测试甲'], ['t61-b', '术语测试乙'], ['t61-trash', '准备进回收站']] as [$slug, $title]) {
    $registry->execute('content.create', ['slug' => $slug, 'title' => $title,
        'body' => '正文', 'status' => 'published', 'author' => 'admin']);
}

[, , $h] = $hit('POST', '/login', [], ['username' => 'admin', 'password' => 'admin123']);
$cookie = 'wx_session=' . cookieValue((string) ($h['Set-Cookie'] ?? ''), 'wx_session');

/** @return array{0:int, 1:string, 2:string} status / Location / body */
$post = static function (array $body, array $q, string $ticket) use ($hit, $cookie): array {
    [$s, $b, $headers] = $hit('POST', '/console/terms', $q,
        $body + ['_form_ticket' => $ticket], ['Cookie' => $cookie]);
    return [$s, (string) ($headers['Location'] ?? ''), $b];
};
$ticketOf = static function (array $q) use ($hit, $cookie, $field): string {
    [, $page] = $hit('GET', '/console/terms', $q, [], ['Cookie' => $cookie]);
    return $field($page, '_form_ticket');
};
$ticket = $ticketOf([]);
truthy($ticket !== '', '没拿到表单票据，后面所有写用例都会误判');

check('匿名 401；默认页是「一个术语都还没有」+ 文章窗口，不是「没有分类」的含糊空态',
    function () use ($hit, $cookie): void {
        [$anon] = $hit('GET', '/console/terms');
        truthy($anon === 401, '匿名应 401，实际 ' . $anon);
        [$s, $page] = $hit('GET', '/console/terms', [], [], ['Cookie' => $cookie]);
        truthy($s === 200, '管理员应 200，实际 ' . $s);
        foreach (['当前筛选下一个术语都还没有', '选中一篇文章', '最多 20 篇',
            '还没有选中文章', '新建分类', 'post_count 不含回收站文章'] as $needle) {
            truthy(str_contains($page, $needle), '页面缺少「' . $needle . '」');
        }
        truthy(str_contains($page, 'data-stats-state="empty"'), '空态没有显式状态标记');
        // href 经主题统一转义，& 在 HTML 里是 &amp;。
        truthy(str_contains($page, 'slug=t61-a'), '文章链接没带 slug');
    });

check('新建分类与标签：303 且落库；同 kind 下重复 slug 是 40911，不是复用',
    function () use ($post, $ticket, $hit, $cookie, $registry): void {
        [$s1, $loc1] = $post(['op' => 'create_category', 'term_slug' => 'tech',
            'name' => '技术', 'description' => '技术文章'], [], $ticket);
        truthy($s1 === 303 && str_contains($loc1, 'outcome=changed'), '新建分类没成功：' . $loc1);
        truthy(str_contains(urldecode($loc1), '记录 ID'), '没回报新术语的 ID');
        [$s2] = $post(['op' => 'create_tag', 'term_slug' => 'hot', 'name' => '热门'], [], $ticket);
        [$s3] = $post(['op' => 'create_tag', 'term_slug' => 'trashme', 'name' => '待删'], [], $ticket);
        truthy($s2 === 303 && $s3 === 303, '新建标签失败');
        $items = (array) ($registry->execute('content.terms', ['kind' => 'any'])['items'] ?? []);
        truthy(count($items) === 3, '库里应有 3 个术语，实际 ' . count($items));

        [$dup, $dupLoc, $dupBody] = $post(['op' => 'create_category', 'term_slug' => 'tech',
            'name' => '换个名字'], [], $ticket);
        truthy($dup === 422 && $dupLoc === '', '重复 slug 应 422 且不跳转，实际 ' . $dup);
        foreach (['40911', '不是复用', '提交没有生效'] as $needle) {
            truthy(str_contains($dupBody, $needle), '重复 slug 没说清「' . $needle . '」');
        }
        [, $page] = $hit('GET', '/console/terms', [], [], ['Cookie' => $cookie]);
        truthy(str_contains($page, 'category:tech'), '清单里没出现新建的分类');
    });

check('非法输入：slug 格式 / 空名称 / 超长名称 / 超长说明都被页面层挡住且回填',
    function () use ($post, $ticket, $field): void {
        $cases = [
            ['BAD_SLUG', '名称', '', 'slug 只能用'],
            ['ok-slug', '', '', '显示名称不能为空'],
            ['ok-slug', str_repeat('字', 61), '', '不能超过 60 字'],
            ['ok-slug', '名称', str_repeat('说', 201), '不能超过 200 字'],
        ];
        foreach ($cases as [$slug, $name, $desc, $needle]) {
            [$s, $loc, $body] = $post(['op' => 'create_tag', 'term_slug' => $slug,
                'name' => $name, 'description' => $desc], [], $ticket);
            truthy($s === 422 && $loc === '', '「' . $needle . '」应 422 不跳转，实际 ' . $s);
            truthy(str_contains($body, $needle), '没点明「' . $needle . '」');
            truthy($field($body, 'term_slug') === $slug, '失败没回填 term_slug');
        }
    });

check('改名：updated=true 才算改动，同值再提交是 no-change 而不是「已保存」',
    function () use ($post, $ticket, $registry): void {
        $id = 0;
        foreach ((array) ($registry->execute('content.terms', ['kind' => 'any'])['items'] ?? []) as $t) {
            if ((string) ($t['slug'] ?? '') === 'tech') $id = (int) ($t['id'] ?? 0);
        }
        truthy($id > 0, '没找到 tech 的 ID');
        [$s, $loc] = $post(['op' => 'term_update', 'id' => (string) $id,
            'name' => '技术进阶', 'description' => '技术文章'], [], $ticket);
        truthy($s === 303 && str_contains($loc, 'outcome=changed'), '改名没成功：' . $loc);
        [$s2, $loc2] = $post(['op' => 'term_update', 'id' => (string) $id,
            'name' => '技术进阶', 'description' => '技术文章'], [], $ticket);
        truthy($s2 === 303 && str_contains($loc2, 'outcome=no-change'), '同值提交应 no-change：' . $loc2);
        truthy(str_contains(urldecode($loc2), 'updated=false'), '没解释 updated=false');
        [$s3, , $b3] = $post(['op' => 'term_update', 'id' => (string) $id], [], $ticket);
        truthy($s3 === 422 && str_contains($b3, '至少要给'), '空字段改名没被挡住');
    });

check('kind 切换只列对应类型；post_count 口径写明不含回收站',
    function () use ($hit, $cookie): void {
        [, $page] = $hit('GET', '/console/terms', ['kind' => 'tag'], [], ['Cookie' => $cookie]);
        truthy(str_contains($page, 'tag:hot') && !str_contains($page, 'category:tech'),
            'kind=tag 没有只列标签');
        truthy(str_contains($page, '不含回收站文章'), '没说明 post_count 口径');
        [, $bad] = $hit('GET', '/console/terms', ['kind' => 'bogus'], [], ['Cookie' => $cookie]);
        truthy(str_contains($bad, 'kind 只能是'), '非法 kind 没被 422 文案说明');
    });

check('文章挂载：预填当前集合，整体替换不是追加（加 2 / 减 1 / 幂等）',
    function () use ($post, $ticket, $ticketOf, $hit, $cookie, $registry): void {
        $q = ['slug' => 't61-a'];
        $t = $ticketOf($q);
        [, $page] = $hit('GET', '/console/terms', $q, [], ['Cookie' => $cookie]);
        truthy(str_contains($page, '还没有挂任何分类或标签'), '空态没说明是真的空');
        [$s, $loc] = $post(['op' => 'attach', 'slug' => 't61-a',
            'terms' => "category:tech\ntag:hot"], $q, $t);
        truthy($s === 303 && str_contains($loc, 'outcome=changed'), '挂载没成功：' . $loc);
        truthy(str_contains(urldecode($loc), '新增 2 条、移除 0 条'), '没回报 added/removed');
        $post2 = (array) $registry->execute('content.post', ['slug' => 't61-a']);
        truthy(count((array) ($post2['terms'] ?? [])) === 2,
            'content.post 的 terms 字段没有返回 2 条（契约字段缺失）');
        [, $page2] = $hit('GET', '/console/terms', $q, [], ['Cookie' => $cookie]);
        truthy(str_contains($page2, 'category:tech') && str_contains($page2, 'tag:hot'),
            '挂载框没预填当前集合');
        truthy(str_contains($page2, '整体替换'), '没说明不是追加');

        [$s3, $loc3] = $post(['op' => 'attach', 'slug' => 't61-a',
            'terms' => 'category:tech'], $q, $t);
        truthy($s3 === 303 && str_contains(urldecode($loc3), '新增 0 条、移除 1 条'),
            '整体替换没把 tag:hot 解绑：' . $loc3);
        [$s4, $loc4] = $post(['op' => 'attach', 'slug' => 't61-a',
            'terms' => 'category:tech'], $q, $t);
        truthy($s4 === 303 && str_contains($loc4, 'outcome=no-change'), '幂等提交应 no-change');
        truthy(str_contains(urldecode($loc4), '集合本来就是这样'), '没解释 no-change');
    });

check('引用不存在 / 写法错在页面层挡住，重复引用去重',
    function () use ($post, $ticket): void {
        $q = ['slug' => 't61-a'];
        [$s1, , $b1] = $post(['op' => 'attach', 'slug' => 't61-a',
            'terms' => 'tag:typo'], $q, $ticket);
        truthy($s1 === 422 && str_contains($b1, '40404'), '不存在的引用没被页面层挡住');
        truthy(str_contains($b1, '不会替你新建'), '没说明接口不会自动创建术语');
        [$s2, , $b2] = $post(['op' => 'attach', 'slug' => 't61-a',
            'terms' => 'bogus'], $q, $ticket);
        truthy($s2 === 422 && str_contains($b2, '写法不对'), '非法引用写法没被挡住');
        [$s3, $loc3] = $post(['op' => 'attach', 'slug' => 't61-a',
            'terms' => "category:tech, category:tech"], $q, $ticket);
        truthy($s3 === 303 && str_contains(urldecode($loc3), '新增 0 条'), '重复引用没去重');
    });

check('清空全部术语要手写文章 slug 确认；确认后回报真正解绑数',
    function () use ($post, $ticket, $registry): void {
        $q = ['slug' => 't61-a'];
        [$s1, , $b1] = $post(['op' => 'attach', 'slug' => 't61-a', 'terms' => ''], $q, $ticket);
        truthy($s1 === 422 && str_contains($b1, '清空'), '空集合没要求确认');
        [$s2, $loc2] = $post(['op' => 'attach', 'slug' => 't61-a', 'terms' => '',
            'confirm' => 't61-a'], $q, $ticket);
        truthy($s2 === 303 && str_contains(urldecode($loc2), '移除 1 条'), '清空没解绑：' . $loc2);
        $post3 = (array) $registry->execute('content.post', ['slug' => 't61-a']);
        truthy((array) ($post3['terms'] ?? []) === [], '清空后库里仍有绑定');
    });

check('删除术语：确认必须手写 slug，成功后回报 relations_removed',
    function () use ($post, $ticket, $registry): void {
        $q = ['slug' => 't61-b'];
        $id = 0;
        foreach ((array) ($registry->execute('content.terms', ['kind' => 'any'])['items'] ?? []) as $t) {
            if ((string) ($t['slug'] ?? '') === 'trashme') $id = (int) ($t['id'] ?? 0);
        }
        truthy($id > 0, '没找到 trashme 的 ID');
        [$s0] = $post(['op' => 'attach', 'slug' => 't61-b', 'terms' => 'tag:trashme'], $q, $ticket);
        truthy($s0 === 303, '没给 t61-b 挂上待删术语');
        [$s1, , $b1] = $post(['op' => 'term_delete', 'id' => (string) $id, 'confirm' => 'wrong'],
            [], $ticket);
        truthy($s1 === 422 && str_contains($b1, '手写'), '删除确认没生效');
        [$s2, $loc2] = $post(['op' => 'term_delete', 'id' => (string) $id, 'confirm' => 'trashme'],
            [], $ticket);
        truthy($s2 === 303, '删除没成功，实际 ' . $s2);
        truthy(str_contains(urldecode($loc2), '清理了 1 条文章绑定关系'),
            '没回报真正清理的关系数：' . urldecode($loc2));
        $post2 = (array) $registry->execute('content.post', ['slug' => 't61-b']);
        truthy((array) ($post2['terms'] ?? []) === [], '删除术语没清掉文章绑定');
    });
