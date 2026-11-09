<?php
declare(strict_types=1);

use Wx\Contracts\DatabaseInterface;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Systems\Api\ApiRegistry;

echo "\n=== 10. 幂等键 / 429 限流 / OpenAPI 3.1 ===\n";

check('Idempotency-Key 重放同一结果，且不重复写入', function (): void {
    resetStorage('idempotency');
    $k = kernel(null);
    $slug = 'idem-' . bin2hex(random_bytes(4));
    $raw = json_encode(['post_slug' => $slug, 'author' => 'g', 'body' => '幂等测试']);
    $r1 = $k->handle(new Request('POST', '/api/v1/comments', [], ['Idempotency-Key' => 'guard-idem-1'], [], (string) $raw));
    $r2 = $k->handle(new Request('POST', '/api/v1/comments', [], ['Idempotency-Key' => 'guard-idem-1'], [], (string) $raw));
    $b1 = json_decode($r1->body(), true);
    $b2 = json_decode($r2->body(), true);
    truthy(($b1['code'] ?? null) === 0 && ($b2['code'] ?? null) === 0, '两次调用应都成功');
    truthy(($b1['data']['id'] ?? null) === ($b2['data']['id'] ?? null), '重放未返回首次结果');
    truthy(($r2->headers()['Idempotency-Replayed'] ?? null) === 'true', '缺少重放标记');
    $db = $k->container()->resolve(DatabaseInterface::class);
    $db->exec('DELETE FROM ' . $db->prefix() . 'comments WHERE post_slug = :s', [':s' => $slug]);
});

check('同键不同参数返回 40902；校验失败不写入缓存', function (): void {
    resetStorage('idempotency');
    $k = kernel(null);
    $a = '{"post_slug":"a","body":"one"}';
    $b = '{"post_slug":"b","body":"two"}';
    $r1 = $k->handle(new Request('POST', '/api/v1/comments', [], ['Idempotency-Key' => 'guard-idem-2'], [], $a));
    truthy((json_decode($r1->body(), true)['code'] ?? null) === 0, '首次写入失败');
    $r2 = $k->handle(new Request('POST', '/api/v1/comments', [], ['Idempotency-Key' => 'guard-idem-2'], [], $b));
    truthy((json_decode($r2->body(), true)['code'] ?? null) === 40902, '同键不同参数应返回 40902');

    $k3 = kernel(null);
    $bad = $k3->handle(new Request('POST', '/api/v1/comments', [], ['Idempotency-Key' => 'guard-idem-3'], [], '{}'));
    truthy((json_decode($bad->body(), true)['code'] ?? null) === 42201, '缺参数应返回 42201');
    $good = $k3->handle(new Request('POST', '/api/v1/comments', [], ['Idempotency-Key' => 'guard-idem-3'], [], $a));
    truthy((json_decode($good->body(), true)['code'] ?? null) === 0, '校验失败不应污染幂等缓存');
    $db = $k3->container()->resolve(DatabaseInterface::class);
    $db->exec('DELETE FROM ' . $db->prefix() . 'comments WHERE post_slug IN (:a,:b)', [':a' => 'a', ':b' => 'b']);
});

check('429 限流：超阈值返回 42901 且带 Retry-After / X-RateLimit 头', function (): void {
    resetStorage('ratelimit');
    $k = new Kernel(getcwd(), ['api' => ['rate_limit' => ['enabled' => true, 'max' => 2, 'window' => 60]]]);
    $codes = [];
    $last = null;
    for ($i = 0; $i < 3; $i++) {
        $last = $k->handle(new Request('GET', '/api/v1/system/info'));
        $codes[] = json_decode($last->body(), true)['code'] ?? null;
    }
    truthy($codes[0] === 0 && $codes[1] === 0, '前两次不应被限流');
    truthy($last->status() === 429 && $codes[2] === 42901, '第三次应返回 42901');
    truthy(isset($last->headers()['Retry-After']) && isset($last->headers()['X-RateLimit-Remaining']), '缺少限流响应头');
});

check('导出的 OpenAPI 3.1 合法、接口与清单 1:1、operationId 唯一', function () use ($root): void {
    $doc = json_decode((string) file_get_contents($root . '/docs/api/openapi.json'), true);
    truthy(is_array($doc) && ($doc['openapi'] ?? null) === '3.1.0', 'OpenAPI 版本不正确');
    $registry = new ApiRegistry($root . '/api/registry.json');
    truthy(isset($doc['components']['securitySchemes']['bearerAuth']), '缺少 bearerAuth 安全声明');
    // 同一路径可承载多个方法（如 GET/POST /api/v1/content/posts），按 operation 逐条核对
    foreach ($registry->endpoints() as $endpoint) {
        $method = strtolower((string) $endpoint['method']);
        $path   = (string) $endpoint['path'];
        truthy(isset($doc['paths'][$path][$method]), 'OpenAPI 缺少接口：' . $endpoint['id']);
        truthy(($doc['paths'][$path][$method]['operationId'] ?? null) === $endpoint['operation'],
            'operationId 与清单不一致：' . $endpoint['id']);
    }
    $ops = [];
    foreach ($doc['paths'] as $methods) {
        foreach ($methods as $op) {
            $id = $op['operationId'] ?? '';
            truthy($id !== '' && !isset($ops[$id]), 'operationId 缺失或重复：' . $id);
            $ops[$id] = true;
        }
    }
    truthy(count($ops) === count($registry->endpoints()), 'operation 总数与接口数不一致');
});
