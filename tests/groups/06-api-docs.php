<?php
declare(strict_types=1);

use Wx\Core\Http\Request;
use Wx\Systems\Api\ApiRegistry;

echo "\n=== 6. API 接口系统与「一接口一文档」 ===\n";

check('56 个接口 1:1 对应文档，章节齐全、无孤儿', function () use ($root): void {
    $registry = new ApiRegistry($root . '/api/registry.json');
    truthy(count($registry->endpoints()) === 56, '接口数量异常：' . count($registry->endpoints()));
    $problems = $registry->documents($root);
    truthy($problems === [], implode(' | ', $problems));
    $ids = array_column($registry->endpoints(), 'id');
    foreach (glob($root . '/docs/api/API-*.md') ?: [] as $file) {
        truthy(in_array(basename($file, '.md'), $ids, true), '孤儿文档：' . $file);
    }
    truthy(is_file($root . '/docs/api/SPEC.md'), '缺少人写的接口规范');
    truthy(is_file($root . '/docs/CONVENTIONS.md'), '缺少工程规矩文件');
});

check('接口注册表拒绝重复定义（ID / 路由 / operation）', function () use ($root): void {
    $data = json_decode((string) file_get_contents($root . '/api/registry.json'), true);
    $data['endpoints'][] = $data['endpoints'][0];
    $tmp = tempnam(sys_get_temp_dir(), 'wxreg');
    file_put_contents($tmp, json_encode($data));
    $threw = false;
    try { new ApiRegistry($tmp); } catch (RuntimeException) { $threw = true; }
    unlink($tmp);
    truthy($threw, '重复接口 ID 应被拒绝');
});

check('统一信封：GET /api/v1/system/info', function (): void {
    $r = kernel(null)->handle(new Request('GET', '/api/v1/system/info'));
    truthy($r->status() === 200, 'HTTP 状态异常：' . $r->status());
    truthy(str_contains($r->contentType(), 'json'), 'Content-Type 非 JSON');
    $body = json_decode($r->body(), true);
    truthy(($body['code'] ?? null) === 0, '业务码不为 0');
    truthy(isset($body['request_id']) && isset($body['meta']['api']), '信封缺少 request_id / meta');
    truthy(($body['meta']['api'] ?? null) === 'API-CORE-001', 'meta.api 不正确');
    truthy(($body['data']['headless'] ?? null) === true, '无主题时应为 headless');
    truthy(($body['data']['systems'] ?? 0) === 15, '系统层数量不正确');
    truthy(($body['data']['api_operations'] ?? 0) === 56, '接口登记数不正确');
});

check('未登记路径返回 40401 且 HTTP 404', function (): void {
    $r = kernel(null)->handle(new Request('GET', '/api/v1/not-exist'));
    $body = json_decode($r->body(), true);
    truthy($r->status() === 404 && ($body['code'] ?? null) === 40401, '404 语义不正确');
});

check('路径存在但方法不匹配返回 40501', function (): void {
    $r = kernel(null)->handle(new Request('POST', '/api/v1/system/info'));
    $body = json_decode($r->body(), true);
    truthy(($body['code'] ?? null) === 40501, '应返回 40501，实际 ' . ($body['code'] ?? 'null'));
});

check('缺必填参数返回 42201 且带字段级 errors', function (): void {
    $r = kernel(null)->handle(new Request('POST', '/api/v1/comments', [], [], [], '{}'));
    $body = json_decode($r->body(), true);
    truthy(($body['code'] ?? null) === 42201, '应返回 42201，实际 ' . ($body['code'] ?? 'null'));
    truthy(($body['errors'][0]['field'] ?? null) === 'post_slug', '缺少字段级错误明细');
});

check('系统层停用后 operation 缺失返回 50101（以 member 为例）', function () use ($root): void {
    // 不能拿 lifecycle 做样本：release 显式依赖它，停用会按 fail-fast 拒绝启动（这本身是正确行为）。
    $k = adminKernel(['systems' => ['disabled' => ['member']]]);
    $r = $k->handle(new Request('GET', '/api/v1/member/levels', [], adminHeaders()));
    $body = json_decode($r->body(), true);
    truthy(($body['code'] ?? null) === 50101, '应返回 50101，实际 ' . ($body['code'] ?? 'null'));
});
