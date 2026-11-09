<?php
declare(strict_types=1);

use Wx\Core\Http\Request;
use Wx\Systems\Emoji\BuiltinEmoji;
use Wx\Systems\Emoji\EmojiCatalogInterface;
use Wx\Systems\Emoji\EmojiParser;

/**
 * 20c · 表情 —— 短码解析、自定义图片、内容不被改写。
 *
 * 只验证「文本怎么变成 token」，不验证主题怎么画。
 */
echo "\n=== 20c. 表情：短码 / 颜文字 / 自定义图片 ===\n";

$site   = tempSite('emoji');
$prefix = 'em_';
dropTables($prefix);
$k = isolatedKernel($site, $prefix);
$k->boot();
$admin = ['Authorization' => 'Bearer test-admin-token'];
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

/** @var EmojiCatalogInterface $catalog */
$catalog = $k->container()->resolve(EmojiCatalogInterface::class);
$parser  = new EmojiParser($catalog);

check('内置目录：Unicode 与颜文字都在，且标记为不可删除', function () use ($catalog): void {
    $all = $catalog->all();
    truthy(count($all) >= 50, '内置条目过少：' . count($all));
    $kinds = array_count_values(array_column($all, 'kind'));
    truthy(($kinds['unicode'] ?? 0) > 40, 'Unicode 条目过少');
    truthy(($kinds['kaomoji'] ?? 0) >= 5, '颜文字条目过少');
    truthy(($all['shrug']['value'] ?? '') === '¯\\_(ツ)_/¯', '颜文字值不正确');
    truthy(BuiltinEmoji::has('smile') && !$catalog->remove('smile'), '内置条目不应可删除');
});

check('解析：已知短码变 token，文本保持原样，未知短码保持字面量', function () use ($parser): void {
    $tokens = $parser->parse('你好 :smile: 再见 :not_a_real:');
    truthy(count($tokens) === 3, 'token 数量应为 3，实际 ' . count($tokens));
    truthy($tokens[0]['type'] === 'text' && $tokens[0]['value'] === '你好 ', '前导文本错误');
    truthy($tokens[1]['type'] === 'emoji' && $tokens[1]['code'] === 'smile', '已知短码未解析');
    truthy($tokens[2]['type'] === 'text' && $tokens[2]['value'] === ' 再见 :not_a_real:', '未知短码应保持字面量');
    truthy($parser->plain('a :tada: b') === 'a :tada: b', 'plain 降级应保留短码');
});

check('解析边界：URL 端口与相邻冒号不被误判', function () use ($parser): void {
    $tokens = $parser->parse('访问 http://127.0.0.1:8080/x 即可');
    truthy(count($tokens) === 1 && $tokens[0]['type'] === 'text', 'URL 不应被解析成表情');
    $glued = $parser->parse('a:smile:b');
    truthy(count($glued) === 1, '紧贴字母的短码不应解析');
});

check('自定义表情：引用本站图片成功，token 值只能是站内路径', function () use ($k, $admin, $png, $catalog): void {
    $up = $k->handle(new Request('POST', '/attach/upload', ['name' => 'shipit.png'], $admin, [], $png));
    $aid = (int) (json_decode($up->body(), true)['data']['id'] ?? 0);
    $reg = $k->handle(new Request('POST', '/api/v1/emojis', [], $admin, [],
        json_encode(['code' => 'shipit', 'attachment_id' => $aid])));
    truthy((json_decode($reg->body(), true)['code'] ?? null) === 0, '登记失败：' . $reg->body());
    $found = $catalog->find('shipit');
    truthy($found !== null && $found['kind'] === 'image', '自定义表情未生效');
    truthy(str_starts_with((string) $found['value'], '/attach/download?id='), '图片地址必须是站内路径：' . $found['value']);
    $GLOBALS['wx_em_aid'] = $aid;
});

check('自定义表情约束：短码冲突、引用不存在、引用非图片都被拒', function () use ($k, $admin, $png): void {
    $aid = (int) ($GLOBALS['wx_em_aid'] ?? 0);
    $dup = $k->handle(new Request('POST', '/api/v1/emojis', [], $admin, [],
        json_encode(['code' => 'shipit', 'attachment_id' => $aid])));
    truthy((json_decode($dup->body(), true)['code'] ?? null) === 40901, '重复短码应 40901');
    $builtin = $k->handle(new Request('POST', '/api/v1/emojis', [], $admin, [],
        json_encode(['code' => 'smile', 'attachment_id' => $aid])));
    truthy((json_decode($builtin->body(), true)['code'] ?? null) === 40901, '内置短码应 40901');
    $missing = $k->handle(new Request('POST', '/api/v1/emojis', [], $admin, [],
        json_encode(['code' => 'ghost', 'attachment_id' => 999999])));
    truthy((json_decode($missing->body(), true)['code'] ?? null) === 40403, '引用不存在应 40403');

    $text = $k->handle(new Request('POST', '/attach/upload', ['name' => 'note.txt'], $admin, [], 'plain text'));
    $tid = (int) (json_decode($text->body(), true)['data']['id'] ?? 0);
    $notImage = $k->handle(new Request('POST', '/api/v1/emojis', [], $admin, [],
        json_encode(['code' => 'note', 'attachment_id' => $tid])));
    truthy((json_decode($notImage->body(), true)['code'] ?? null) === 42201, '非图片附件应 42201');
    $badCode = $k->handle(new Request('POST', '/api/v1/emojis', [], $admin, [],
        json_encode(['code' => 'Bad Code!', 'attachment_id' => $aid])));
    truthy((json_decode($badCode->body(), true)['code'] ?? null) === 42201, '非法短码应 42201');
});

check('删除自定义表情：历史正文不被改写，token 退回字面量', function () use ($k, $admin): void {
    $body = '历史内容 :shipit: 仍在';
    $before = json_decode($k->handle(new Request('POST', '/api/v1/comments', [], $admin, [],
        json_encode(['post_slug' => 'first-post', 'body' => $body])))->body(), true);
    truthy(($before['code'] ?? null) === 0, '发评论失败');
    $del = $k->handle(new Request('POST', '/api/v1/emojis/delete', [], $admin, [], json_encode(['code' => 'shipit'])));
    truthy((json_decode($del->body(), true)['code'] ?? null) === 0, '删除自定义表情失败');

    $list = json_decode($k->handle(new Request('GET', '/api/v1/comments', ['post_slug' => 'first-post'], $admin))->body(), true);
    $row = array_values(array_filter($list['data']['items'],
        static fn(array $i): bool => $i['body'] === $body))[0] ?? null;
    truthy($row !== null, '正文被改写了：原样存储才是可逆的前提');
    $kinds = array_column((array) $row['tokens'], 'type');
    truthy(!in_array('emoji', $kinds, true), '删除后不应再解析出图片表情');
});

check('文章与评论共用同一解析入口：都带 body_tokens / tokens', function () use ($k, $admin): void {
    $post = json_decode($k->handle(new Request('GET', '/api/v1/content/post', ['slug' => 'first-post'], $admin))->body(), true);
    truthy(isset($post['data']['body_tokens']) && is_array($post['data']['body_tokens']), '文章缺少 body_tokens');
    $list = json_decode($k->handle(new Request('GET', '/api/v1/content/posts', ['status' => 'published'], $admin))->body(), true);
    $first = $list['data']['items'][0] ?? [];
    truthy(isset($first['body_tokens']), '文章列表缺少 body_tokens');
    $comments = json_decode($k->handle(new Request('GET', '/api/v1/comments', ['post_slug' => 'first-post'], $admin))->body(), true);
    truthy(isset($comments['data']['items'][0]['tokens']), '评论缺少 tokens');
});

check('目录接口：按 kind 过滤，自定义条目带 custom 标记', function () use ($k, $admin): void {
    $ka = json_decode($k->handle(new Request('GET', '/api/v1/emojis', ['kind' => 'kaomoji']))->body(), true);
    truthy(($ka['data']['total'] ?? 0) >= 5, '颜文字过滤结果过少');
    foreach ($ka['data']['items'] as $item) truthy($item['kind'] === 'kaomoji', '过滤未生效：' . $item['code']);
    $all = json_decode($k->handle(new Request('GET', '/api/v1/emojis'))->body(), true);
    $custom = array_values(array_filter($all['data']['items'], static fn(array $i): bool => $i['custom'] === true));
    truthy($custom === [], '删除后不应再有自定义条目');
});

dropTables($prefix);
\Wx\Systems\Lifecycle\Fs::removeDir($site);
