<?php
declare(strict_types=1);

use Wx\Core\Http\Request;

echo "\n=== 27b. 附件流式下载（内存边界） ===\n";

/**
 * 这一组只回答一个问题：下载大文件时，字节是否整份驻留在内存里。
 * 证据是「响应标记为流式 + body 为空 + Content-Length 等于文件大小 + 实际输出字节数一致」；
 * 小文件必须继续走 body —— 否则既有的字节级断言会集体失效。
 */

$mkAdmin = static fn(): \Wx\Core\Kernel => adminKernel(['api' => ['rate_limit' => ['enabled' => false]]]);

check('大附件流式下载：不把整文件读进 body，字节与存储一致', function () use ($mkAdmin): void {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $up = $mkAdmin()->handle(new Request('POST', '/attach/upload', ['name' => 'stream.png'], adminHeaders(), [], $png));
    $data = (array) (json_decode($up->body(), true)['data'] ?? []);
    $id = (int) ($data['id'] ?? 0);
    $sha = (string) ($data['sha256'] ?? '');
    truthy($id > 0 && strlen($sha) === 64 && ctype_xdigit($sha), '上传未返回合法 id / sha');

    $path = getcwd() . '/storage/attachments/' . substr($sha, 0, 2) . '/' . substr($sha, 2, 2) . '/' . $sha;
    // 人为放大到阈值以上（2MB）：内容寻址只按记录里的 sha 定位，下载不校验内容。
    $fh = fopen($path, 'wb');
    $chunk = str_repeat("\0", 256 * 1024);
    for ($i = 0; $i < 8; $i++) fwrite($fh, $chunk);
    fclose($fh);
    unset($chunk);

    try {
        $r = $mkAdmin()->handle(new Request('GET', '/attach/download', ['id' => $id], adminHeaders()));
        truthy($r->status() === 200, '下载状态异常：' . $r->status());
        truthy($r->isStreamed(), '超过阈值应走流式输出');
        truthy($r->body() === '', '流式响应不应把字节放进 body');
        truthy((int) ($r->headers()['Content-Length'] ?? 0) === 2 * 1024 * 1024, 'Content-Length 应为文件大小');

        ob_start();
        $r->send();
        $out = (string) ob_get_clean();
        truthy(strlen($out) === 2 * 1024 * 1024, '流式输出字节数不符：' . strlen($out));
    } finally {
        @unlink($path);
        $mkAdmin()->handle(new Request('POST', '/api/v1/attachments/delete', ['id' => $id], adminHeaders()));
    }
});

check('小附件保持字节语义：低于阈值不进流式，body 逐字一致', function () use ($mkAdmin): void {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $up = $mkAdmin()->handle(new Request('POST', '/attach/upload', ['name' => 'small.png'], adminHeaders(), [], $png));
    $data = (array) (json_decode($up->body(), true)['data'] ?? []);
    $id = (int) ($data['id'] ?? 0);
    $sha = (string) ($data['sha256'] ?? '');
    try {
        $r = $mkAdmin()->handle(new Request('GET', '/attach/download', ['id' => $id], adminHeaders()));
        truthy(!$r->isStreamed(), '小文件不应进流式');
        truthy($r->body() === $png, '小文件字节与上传不一致');
    } finally {
        if (strlen($sha) === 64 && ctype_xdigit($sha)) {
            @unlink(getcwd() . '/storage/attachments/' . substr($sha, 0, 2) . '/' . substr($sha, 2, 2) . '/' . $sha);
        }
        $mkAdmin()->handle(new Request('POST', '/api/v1/attachments/delete', ['id' => $id], adminHeaders()));
    }
});
