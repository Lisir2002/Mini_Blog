<?php
declare(strict_types=1);

use Wx\Core\Http\Request;
use Wx\Core\Kernel;

/**
 * 22b · 响应经济（规矩 T 第三条：优化不改变语义）。
 *
 * 用首页这个稳定响应做端到端验证：关闭零增强头；开启后 ETag / gzip / 304
 * 全部生效，且解码后与关闭时逐字相同；带身份的响应一律不参与协商。
 */
echo "\n=== 22b. 响应经济：ETag / 304 / gzip 与个性化排除 ===\n";

$mk = static fn(array $cfg): Kernel => new Kernel($root, $cfg);
$onCfg = ['performance' => [
    'enabled' => true,
    'economy' => ['min_bytes' => 64, 'gzip' => true, 'max_age' => 60],
]];
$offCfg = ['performance' => ['enabled' => false]];

check('关闭时零增强头，body 原样输出', function () use ($mk, $offCfg): void {
    $r = $mk($offCfg)->handle(new Request('GET', '/', [], ['Accept-Encoding' => 'gzip']));
    $h = $r->headers();
    foreach (['ETag', 'Server-Timing', 'Content-Encoding', 'Vary', 'Cache-Control'] as $name) {
        truthy(!isset($h[$name]), '关闭时不应出现 ' . $name);
    }
    truthy($r->status() === 200 && $r->body() !== '', '关闭时仍应正常输出');
});

check('开启时 ETag 形状、gzip 生效且解码后逐字等价', function () use ($mk, $onCfg, $offCfg): void {
    $off = $mk($offCfg)->handle(new Request('GET', '/', [], []));
    $on = $mk($onCfg)->handle(new Request('GET', '/', [], ['Accept-Encoding' => 'gzip']));
    $h = $on->headers();

    truthy(preg_match('/^W\/"[0-9a-f]{16}-[ig]"$/', (string) ($h['ETag'] ?? '')) === 1, 'ETag 形状不合规');
    truthy(str_contains((string) ($h['Server-Timing'] ?? ''), 'queries'), 'Server-Timing 未自证工作量');
    truthy(($h['Content-Encoding'] ?? '') === 'gzip', '应启用 gzip');
    truthy(strlen($on->body()) < strlen($off->body()), 'gzip 后字节未减少');
    truthy((string) gzdecode($on->body()) === $off->body(), '解码后与关闭时不一致');
});

check('ETag 命中返回 304、body 归零且不残留编码头', function () use ($mk, $onCfg): void {
    $first = $mk($onCfg)->handle(new Request('GET', '/', [], ['Accept-Encoding' => 'gzip']));
    $etag = (string) ($first->headers()['ETag'] ?? '');
    truthy($etag !== '', '首次响应应带 ETag');

    $again = $mk($onCfg)->handle(new Request('GET', '/', [], [
        'Accept-Encoding' => 'gzip', 'If-None-Match' => $etag,
    ]));
    truthy($again->status() === 304, '协商命中应返回 304，实际 ' . $again->status());
    truthy($again->body() === '', '304 不应有实体');
    truthy(!isset($again->headers()['Content-Encoding']), '304 不得残留 Content-Encoding');
    truthy(($again->headers()['ETag'] ?? '') === $etag, '304 应回带同一个 ETag');
});

check('ETag 不命中仍是 200；带身份或非 GET 一律不协商', function () use ($mk, $onCfg): void {
    $miss = $mk($onCfg)->handle(new Request('GET', '/', [], [
        'Accept-Encoding' => 'gzip', 'If-None-Match' => 'W/"deadbeefdeadbeef-i"',
    ]));
    truthy($miss->status() === 200 && $miss->body() !== '', '不命中应完整返回');

    $auth = $mk($onCfg)->handle(new Request('GET', '/', [], [
        'Accept-Encoding' => 'gzip', 'Authorization' => 'Bearer some-token',
    ]));
    truthy(!isset($auth->headers()['ETag']), '带身份响应不得参与协商');

    $post = $mk($onCfg)->handle(new Request('POST', '/', [], ['Accept-Encoding' => 'gzip']));
    truthy(!isset($post->headers()['ETag']), '非 GET 响应不得参与协商');
});
