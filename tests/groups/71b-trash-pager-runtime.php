<?php
declare(strict_types=1);

use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Http\Request;
use Wx\Pages\ContentAdmin\ContentAdminQuery;

/**
 * 71b · 回收站深翻页：运行时闭环
 *
 * 与 71 组同属一件事的两半：本组从真实 HTTP + 真实 MariaDB 取证，锁住
 * 45 篇软删除文章翻三页不重不漏、total 不受游标影响、末页无下一页、
 * 非法游标是显式 422、PRG 留在原页，以及「本页被清光」的边界文案。
 */
echo "\n=== 71b. 回收站深翻页：运行时闭环 ===\n";

$site   = tempSite('trash-pager');
$prefix = 'tp_';
dropTables($prefix);
$k = isolatedKernel($site, $prefix, ['theme' => ['active' => 'glass']]);
$k->boot();
$reg   = $k->container()->resolve(ApiRegistryInterface::class);
$admin = ['Cookie' => 'wx_session=test-admin-token'];

/** 45 篇软删除文章：三页（20 / 20 / 5），id 顺序与 slug 序号一致。 */
$slugs = [];
for ($i = 1; $i <= 45; $i++) {
    $slug = 'tp-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
    $slugs[] = $slug;
    $reg->execute('content.create', ['slug' => $slug, 'title' => '回收站 ' . $i,
        'body' => '正文', 'status' => 'published', 'author' => 'admin']);
    $reg->execute('content.delete', ['slug' => $slug]);
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
$ticketOf = static function (array $q) use ($hit, $field, $admin): string {
    [, $page] = $hit('/console/content', $q, 'GET', [], $admin);
    return $field($page, '_form_ticket');
};
/** @return list<string> */
$slugsOf = static function (array $data): array {
    return array_values(array_map(static fn(array $it): string => (string) ($it['slug'] ?? ''),
        (array) ($data['items'] ?? [])));
};

$q = new ContentAdminQuery($reg);
$p1 = $q->trash('');
$p2 = $q->trash($p1['next_cursor']);
$p3 = $q->trash($p2['next_cursor']);

check('接口本就支持游标：content.trash 出站带 items / next_cursor / total',
    function () use ($reg): void {
        $r = $reg->execute('content.trash', ['limit' => 20, 'cursor' => '']);
        foreach (['items', 'next_cursor', 'total'] as $k) {
            truthy(array_key_exists($k, $r), "content.trash 出站缺 {$k}");
        }
        truthy((int) $r['total'] === 45, '回收站总数不是 45，实际 ' . (string) $r['total']);
    });

check('三页不重不漏：20 / 20 / 5，total 恒定，末页无下一页',
    function () use ($p1, $p2, $p3, $slugsOf, $slugs): void {
        truthy($p1['shown'] === 20 && $p2['shown'] === 20 && $p3['shown'] === 5,
            '三页条数不对：' . json_encode([$p1['shown'], $p2['shown'], $p3['shown']]));
        truthy($p1['next_cursor'] !== '' && $p2['next_cursor'] !== '' && $p3['next_cursor'] === '',
            '游标链没在末页收口');
        truthy($p1['total'] === 45 && $p2['total'] === 45 && $p3['total'] === 45,
            'total 被游标改写了，翻页会越翻越少');
        $all = array_merge($slugsOf($p1), $slugsOf($p2), $slugsOf($p3));
        truthy(count($all) === 45 && count(array_unique($all)) === 45, '翻页出现重复或跳篇');
        truthy($all === $slugs, '三页顺序与 id 升序不一致');
    });

check('HTTP 首页：只有下一页，行表单不带空 cursor',
    function () use ($hit, $admin): void {
        [$s, $body] = $hit('/console/content', ['view' => 'trash'], 'GET', [], $admin);
        truthy($s === 200, '首页状态码 ' . $s);
        truthy(str_contains($body, '下一页'), '首页没给下一页入口');
        truthy(!str_contains($body, '回到第一页'), '首页不该有回到第一页');
        truthy(!str_contains($body, 'name="cursor"'), '首页塞了空 cursor hidden');
    });

check('HTTP 中间页：双向链接齐备，行表单把当前游标带回 POST',
    function () use ($hit, $admin, $p1): void {
        [$s, $body] = $hit('/console/content',
            ['view' => 'trash', 'cursor' => $p1['next_cursor']], 'GET', [], $admin);
        truthy($s === 200, '第二页状态码 ' . $s);
        truthy(str_contains($body, '回到第一页') && str_contains($body, '下一页'), '第二页缺方向');
        truthy(str_contains($body, 'name="cursor"'), '行表单没带回 cursor');
        truthy(str_contains($body, 'value="' . $p1['next_cursor'] . '"'),
            '行表单里的 cursor 值不对');
    });

check('HTTP 末页：只留回到第一页，不再给下一页',
    function () use ($hit, $admin, $p2): void {
        [$s, $body] = $hit('/console/content',
            ['view' => 'trash', 'cursor' => $p2['next_cursor']], 'GET', [], $admin);
        truthy($s === 200, '末页状态码 ' . $s);
        truthy(str_contains($body, '回到第一页'), '末页没有脱困链接');
        truthy(!str_contains($body, '下一页 →'), '末页还在给下一页');
    });

check('非法游标是显式 422，不静默当第一页，也不误报成 503',
    function () use ($hit, $admin): void {
        [$s, $body] = $hit('/console/content',
            ['view' => 'trash', 'cursor' => '!!!'], 'GET', [], $admin);
        truthy($s === 422, '非法游标应 422，实际 ' . $s);
        truthy(str_contains($body, '回收站翻页参数不合法'), '没有显式说明是参数问题');
        truthy(str_contains($body, 'data-stats-state="invalid"'), '降级状态没标成 invalid');
        truthy(str_contains($body, '回到回收站第一页'), '422 没给脱困链接');
        truthy(!str_contains($body, '内容列表读取失败'), '把请求错误误报成服务故障');
    });

check('purge 失败态仍留在回收站视图，游标不被丢掉',
    function () use ($hit, $admin, $ticketOf, $p1): void {
        $t = $ticketOf(['view' => 'trash', 'cursor' => $p1['next_cursor']]);
        [$s, $body] = $hit('/console/content', [], 'POST', [
            'op' => 'purge', 'slug' => 'tp-22', 'confirm' => 'wrong',
            'cursor' => $p1['next_cursor'], '_form_ticket' => $t,
        ], $admin);
        truthy($s === 422, '二次确认失败应 422，实际 ' . $s);
        truthy(str_contains($body, 'name="cursor"'), '失败态掉回 live 视图，游标丢了');
    });

check('边界：本页内容被清光后说「这一页空了」，不谎称回收站是空的',
    function () use ($reg, $hit, $admin, $p2): void {
        foreach (['tp-41', 'tp-42', 'tp-43', 'tp-44', 'tp-45'] as $slug) {
            $reg->execute('content.purge', ['slug' => $slug, 'confirm' => $slug]);
        }
        [$s, $body] = $hit('/console/content',
            ['view' => 'trash', 'cursor' => $p2['next_cursor']], 'GET', [], $admin);
        truthy($s === 200, '空页状态码 ' . $s);
        truthy(str_contains($body, '这一页已经没有内容'), '空页没有显式说明');
        truthy(!str_contains($body, '回收站是空的'), '把空页说成了空回收站');
        truthy(str_contains($body, '仍有 40 篇'), '没给出真实总数 40');
    });

check('PRG：第二页恢复一条后 303 带回 cursor，不被弹回第一页',
    function () use ($hit, $admin, $ticketOf, $p1): void {
        $t = $ticketOf(['view' => 'trash', 'cursor' => $p1['next_cursor']]);
        [$s, , $h] = $hit('/console/content', [], 'POST', [
            'op' => 'restore', 'slug' => 'tp-21',
            'cursor' => $p1['next_cursor'], '_form_ticket' => $t,
        ], $admin);
        truthy($s === 303, '恢复成功应 303，实际 ' . $s);
        $to = (string) ($h['Location'] ?? '');
        truthy(str_contains($to, 'view=trash') && str_contains($to, 'cursor='),
            '303 丢了 cursor：' . $to);
    });
