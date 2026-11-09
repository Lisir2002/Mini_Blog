<?php
declare(strict_types=1);

use Wx\Core\Http\Request;
use Wx\Core\Kernel;

/**
 * 20b · 附件 —— 内容寻址、类型白名单、二进制通道与下载授权。
 *
 * 只验证「字节能不能安全地进来、能不能安全地出去」，不涉及文章正文。
 */
echo "\n=== 20b. 附件：内容寻址 / 白名单 / 下载授权 ===\n";

$site   = tempSite('attach');
$prefix = 'at_';
dropTables($prefix);
$k = isolatedKernel($site, $prefix);
$k->boot();
$admin = ['Authorization' => 'Bearer test-admin-token'];
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

$upload = static function (Kernel $k, string $name, string $bytes, array $query = [], array $headers = []): array {
    $r = $k->handle(new Request('POST', '/attach/upload', ['name' => $name] + $query, $headers, [], $bytes));
    return [$r->status(), json_decode($r->body(), true) ?: [], $r];
};

check('上传：文件名不进路径，穿越片段被净化，哈希即地址', function () use ($k, $admin, $png, $upload): void {
    [$status, $body] = $upload($k, '../../etc/cover.png', $png, ['post_slug' => 'first-post'], $admin);
    truthy($status === 200 && ($body['code'] ?? null) === 0, '上传失败：' . json_encode($body, JSON_UNESCAPED_UNICODE));
    truthy(($body['data']['name'] ?? '') === 'cover.png', '展示名未净化：' . ($body['data']['name'] ?? ''));
    truthy(strlen((string) ($body['data']['sha256'] ?? '')) === 64, '缺少内容哈希');
    truthy(($body['data']['mime'] ?? '') === 'image/png', 'MIME 应由服务端推导');
    $GLOBALS['wx_at_id'] = (int) ($body['data']['id'] ?? 0);
    $GLOBALS['wx_at_sha'] = (string) ($body['data']['sha256'] ?? '');
});

check('内容寻址：同字节两次上传共用一个存储文件', function () use ($k, $admin, $png, $upload, $site): void {
    [$status, $body] = $upload($k, 'copy.png', $png, [], $admin);
    truthy($status === 200, '第二次上传失败');
    truthy(($body['data']['sha256'] ?? '') === $GLOBALS['wx_at_sha'], '相同内容应得到相同哈希');
    $sha = (string) $GLOBALS['wx_at_sha'];
    $path = $site . '/storage/attachments/' . substr($sha, 0, 2) . '/' . substr($sha, 2, 2) . '/' . $sha;
    truthy(is_file($path), '按哈希分片落盘失败：' . $path);
    truthy(($body['data']['id'] ?? 0) !== $GLOBALS['wx_at_id'], '元数据应各自独立成行');
});

check('类型白名单：可执行与可渲染类型一律拒绝', function () use ($k, $admin, $png, $upload): void {
    foreach (['shell.php' => '<?php echo 1;', 'x.html' => '<script>alert(1)</script>',
        'x.svg' => '<svg onload="alert(1)"></svg>', 'x.js' => 'alert(1)'] as $name => $bytes) {
        [$status, $body] = $upload($k, $name, $bytes, [], $admin);
        truthy($status === 422 && ($body['code'] ?? null) === 42201, $name . ' 应被拒绝');
    }
});

check('魔数校验：改名的文本不能冒充图片', function () use ($k, $admin, $upload): void {
    [$status, $body] = $upload($k, 'fake.png', 'this is definitely not a png', [], $admin);
    truthy($status === 422 && ($body['code'] ?? null) === 42201, '伪造 PNG 应被拒绝');
    [$okStatus] = $upload($k, 'note.txt', 'plain text, no nul byte', [], $admin);
    truthy($okStatus === 200, '合法文本文件应允许');
    [$badStatus] = $upload($k, 'bin.txt', "text\0binary", [], $admin);
    truthy($badStatus === 422, '含 NUL 的文本应被拒绝');
});

check('下载：类型来自白名单，强制 nosniff / CSP / attachment，字节一致', function () use ($k, $admin, $png): void {
    $id = (int) $GLOBALS['wx_at_id'];
    $r = $k->handle(new Request('GET', '/attach/download', ['id' => $id], $admin));
    truthy($r->status() === 200, '下载失败：' . $r->status());
    truthy($r->contentType() === 'image/png', 'Content-Type 错误：' . $r->contentType());
    truthy($r->body() === $png, '下载字节与上传不一致');
    truthy(($r->headers()['X-Content-Type-Options'] ?? '') === 'nosniff', '缺少 nosniff');
    truthy(str_contains((string) ($r->headers()['Content-Security-Policy'] ?? ''), "default-src 'none'"), '缺少 CSP');
    truthy(str_starts_with((string) ($r->headers()['Content-Disposition'] ?? ''), 'attachment;'), '默认应为附件下载');
    truthy(str_contains((string) ($r->headers()['Content-Disposition'] ?? ''), "filename*=UTF-8''"), '缺少 UTF-8 文件名');
});

check('私有附件：匿名 40101，上传者 200，inline 仅图片可用', function () use ($k, $admin, $png, $upload): void {
    [, $body] = $upload($k, 'secret.png', $png, ['visibility' => 'private'], $admin);
    $id = (int) ($body['data']['id'] ?? 0);
    $anon = $k->handle(new Request('GET', '/attach/download', ['id' => $id]));
    truthy($anon->status() === 401 && (json_decode($anon->body(), true)['code'] ?? null) === 40101,
        '私有附件匿名下载应 40101');
    truthy($k->handle(new Request('GET', '/attach/download', ['id' => $id], $admin))->status() === 200, '上传者应可下载');
    $inline = $k->handle(new Request('GET', '/attach/download', ['id' => $id, 'disposition' => 'inline'], $admin));
    truthy(str_starts_with((string) ($inline->headers()['Content-Disposition'] ?? ''), 'inline;'), '图片应支持 inline');
});

check('下载失败：不存在与已软删都返回 40403', function () use ($k, $admin): void {
    $missing = $k->handle(new Request('GET', '/attach/download', ['id' => 999999], $admin));
    truthy($missing->status() === 404 && (json_decode($missing->body(), true)['code'] ?? null) === 40403, '不存在应 40403');
    $id = (int) $GLOBALS['wx_at_id'];
    $k->handle(new Request('POST', '/api/v1/attachments/delete', [], $admin, [], json_encode(['id' => $id])));
    $gone = $k->handle(new Request('GET', '/attach/download', ['id' => $id], $admin));
    truthy($gone->status() === 404, '软删后不应还能下载');
});

check('配额：单篇文章附件数达到上限后拒绝', function () use ($site, $prefix, $png, $upload): void {
    $small = isolatedKernel($site, $prefix, ['attachment' => ['max_per_post' => 1]]);
    $small->boot();
    $h = ['Authorization' => 'Bearer test-admin-token'];
    [$first] = $upload($small, 'a.png', $png, ['post_slug' => 'second-post'], $h);
    [$second, $body] = $upload($small, 'b.png', $png, ['post_slug' => 'second-post'], $h);
    truthy($first === 200 && $second === 422, '配额未生效：' . json_encode($body, JSON_UNESCAPED_UNICODE));
});

check('元数据：改名同样净化，可见性可切换，列表按文章过滤', function () use ($k, $admin, $png, $upload): void {
    [, $body] = $upload($k, 'doc.png', $png, ['post_slug' => 'second-post'], $admin);
    $id = (int) ($body['data']['id'] ?? 0);
    $up = $k->handle(new Request('POST', '/api/v1/attachments/update', [], $admin, [],
        json_encode(['id' => $id, 'name' => '../报告.png', 'visibility' => 'private'])));
    truthy((json_decode($up->body(), true)['code'] ?? null) === 0, '更新失败：' . $up->body());
    $list = json_decode($k->handle(new Request('GET', '/api/v1/attachments', ['post_slug' => 'second-post'], $admin))->body(), true);
    $row = array_values(array_filter($list['data']['items'], static fn(array $i): bool => $i['id'] === $id))[0] ?? null;
    truthy($row !== null && $row['name'] === '报告.png', '改名未净化：' . ($row['name'] ?? ''));
    truthy(($row['visibility'] ?? '') === 'private', '可见性未更新');
    $anon = json_decode($k->handle(new Request('GET', '/api/v1/attachments', ['post_slug' => 'second-post']))->body(), true);
    truthy(array_values(array_filter($anon['data']['items'], static fn(array $i): bool => $i['id'] === $id)) === [],
        '私有附件不应出现在匿名列表里');
});

dropTables($prefix);
\Wx\Systems\Lifecycle\Fs::removeDir($site);
