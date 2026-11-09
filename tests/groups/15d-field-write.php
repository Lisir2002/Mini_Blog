<?php
declare(strict_types=1);

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\DatabaseInterface;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;

echo "\n=== 15d. 字段级写校验：resource.field 用在写路径 ===\n";

function fieldKernel(): Kernel
{
    return adminKernel(['api' => ['tokens' => [
        'test-admin-token'     => ['role' => 'admin', 'username' => 'admin', 'user_id' => 1],
        't-subscriber-token'   => ['role' => 'subscriber', 'username' => 'sub', 'user_id' => 8],
    ]], 'permission' => ['cache' => ['driver' => 'memory']]]);
}

/** @return array<string, mixed> */
function postJson(Kernel $k, string $path, array $payload, array $headers): array
{
    $r = $k->handle(new Request(
        'POST', $path, [], $headers + ['Content-Type' => 'application/json'],
        [], (string) json_encode($payload, JSON_UNESCAPED_UNICODE)
    ));
    return ['status' => $r->status(), 'body' => (array) json_decode($r->body(), true)];
}

check('字段级 deny 只拦 body，同一次请求的其它字段不受影响', function (): void {
    $k = fieldKernel();
    $slug = 't15d-field-' . bin2hex(random_bytes(3));
    $created = postJson($k, '/api/v1/content/posts', [
        'title' => '字段级写测试', 'body' => '原始正文', 'slug' => $slug, 'status' => 'draft',
    ], adminHeaders());
    truthy(($created['body']['code'] ?? null) === 0, '创建测试文章失败');

    // 资源标识统一为 slug：写路径与读路径必须指向同一个标识
    $acl = $k->container()->resolve(AccessControlInterface::class);
    $db  = $k->container()->resolve(DatabaseInterface::class);
    $acl->setAcl([
        'type' => 'post', 'id' => $slug, 'subject' => '*',
        'capability' => 'content.edit', 'allow' => false, 'field' => 'body',
    ]);
    try {
        $denied = postJson($k, '/api/v1/content/post/update', [
            'slug' => $slug, 'body' => '被禁正文',
        ], adminHeaders());
        truthy(($denied['body']['code'] ?? null) === 40303, '字段级 deny 未拦下 body 写入');

        $title = postJson($k, '/api/v1/content/post/update', [
            'slug' => $slug, 'title' => '可以改的标题',
        ], adminHeaders());
        truthy(($title['body']['code'] ?? null) === 0, '字段隔离失效：title 也被拦住');
        truthy(($title['body']['data']['updated'] ?? []) === ['title'], 'updated 字段不正确');
    } finally {
        $db->pdo()->prepare('DELETE FROM ' . $db->prefix() . 'permission_acl WHERE resource_id = ?')
            ->execute([$slug]);
        $db->pdo()->prepare('DELETE FROM ' . $db->prefix() . 'content_posts WHERE slug = ?')
            ->execute([$slug]);
    }
});

check('无 content.edit 的主体整次写入被拒，不做部分写入', function (): void {
    $k = fieldKernel();
    $slug = 't15d-role-' . bin2hex(random_bytes(3));
    postJson($k, '/api/v1/content/posts', [
        'title' => '角色测试', 'body' => '正文', 'slug' => $slug, 'status' => 'draft',
    ], adminHeaders());

    $db = $k->container()->resolve(DatabaseInterface::class);
    try {
        $r = postJson($k, '/api/v1/content/post/update', [
            'slug' => $slug, 'title' => '越权标题',
        ], roleHeaders('subscriber'));
        truthy(($r['body']['code'] ?? null) === 40303, 'subscriber 越权写入未被拒绝');

        $row = $db->pdo()->prepare('SELECT title FROM ' . $db->prefix() . 'content_posts WHERE slug = ?');
        $row->execute([$slug]);
        truthy((string) $row->fetchColumn() === '角色测试', '被拒写入仍修改了数据');
    } finally {
        $db->pdo()->prepare('DELETE FROM ' . $db->prefix() . 'content_posts WHERE slug = ?')->execute([$slug]);
    }
});

check('字段白名单与状态校验：空字段 / 非法状态返回 42201', function (): void {
    $k = fieldKernel();
    $slug = 't15d-valid-' . bin2hex(random_bytes(3));
    postJson($k, '/api/v1/content/posts', [
        'title' => '校验测试', 'body' => '正文', 'slug' => $slug, 'status' => 'draft',
    ], adminHeaders());

    $db = $k->container()->resolve(DatabaseInterface::class);
    try {
        $empty = postJson($k, '/api/v1/content/post/update', ['slug' => $slug], adminHeaders());
        truthy(($empty['body']['code'] ?? null) === 42201, '没有可更新字段时未报 42201');

        $bad = postJson($k, '/api/v1/content/post/update', [
            'slug' => $slug, 'status' => 'deleted',
        ], adminHeaders());
        truthy(($bad['body']['code'] ?? null) === 42201, '非法状态未报 42201');

        $ok = postJson($k, '/api/v1/content/post/update', [
            'slug' => $slug, 'status' => 'published',
        ], adminHeaders());
        truthy(($ok['body']['code'] ?? null) === 0, '合法状态更新失败');
    } finally {
        $db->pdo()->prepare('DELETE FROM ' . $db->prefix() . 'content_posts WHERE slug = ?')->execute([$slug]);
    }
});

check('字段级写沿用字段级 deny 的精确匹配：不带 field 的请求不触发', function (): void {
    $k = fieldKernel();
    $slug = 't15d-scope-' . bin2hex(random_bytes(3));
    postJson($k, '/api/v1/content/posts', [
        'title' => '作用域测试', 'body' => '正文', 'slug' => $slug, 'status' => 'draft',
    ], adminHeaders());
    $acl = $k->container()->resolve(AccessControlInterface::class);
    $db  = $k->container()->resolve(DatabaseInterface::class);
    $acl->setAcl([
        'type' => 'post', 'id' => $slug, 'subject' => '*',
        'capability' => 'content.edit', 'allow' => false, 'field' => 'body',
    ]);
    try {
        $admin = ['role' => 'admin', 'username' => 'admin', 'user_id' => 1];
        truthy($acl->decide($admin, 'content.edit', ['type' => 'post', 'id' => $slug]) === AccessControlInterface::ALLOW,
            '不带 field 的整资源判定被字段级条目误伤');
        truthy($acl->decide($admin, 'content.edit', ['type' => 'post', 'id' => $slug, 'field' => 'body']) === AccessControlInterface::DENY,
            '字段级条目未精确命中');
    } finally {
        $db->pdo()->prepare('DELETE FROM ' . $db->prefix() . 'permission_acl WHERE resource_id = ?')
            ->execute([$slug]);
        $db->pdo()->prepare('DELETE FROM ' . $db->prefix() . 'content_posts WHERE slug = ?')->execute([$slug]);
    }
});

check('读写共用同一个资源标识：按 slug 登记的 ACL 同时作用于读与写', function (): void {
    $k = fieldKernel();
    $slug = 't15d-ident-' . bin2hex(random_bytes(3));
    postJson($k, '/api/v1/content/posts', [
        'title' => '标识一致性', 'body' => '正文', 'slug' => $slug, 'status' => 'published',
    ], adminHeaders());
    $acl = $k->container()->resolve(AccessControlInterface::class);
    $db  = $k->container()->resolve(DatabaseInterface::class);
    // 整资源级 deny：读与写必须同时被拦，证明两条路径认的是同一个 id
    $acl->setAcl([
        'type' => 'post', 'id' => $slug, 'subject' => '*',
        'capability' => 'content.edit', 'allow' => false,
    ]);
    $acl->setAcl([
        'type' => 'post', 'id' => $slug, 'subject' => '*',
        'capability' => 'content.read', 'allow' => false,
    ]);
    try {
        $anon = null;
        truthy($acl->decide($anon, 'content.read', ['type' => 'post', 'id' => $slug]) === AccessControlInterface::DENY,
            '读路径未命中按 slug 登记的条目');
        $write = postJson($k, '/api/v1/content/post/update', ['slug' => $slug, 'title' => '不该生效'], adminHeaders());
        truthy(($write['body']['code'] ?? null) === 40303, '写路径未命中按 slug 登记的条目');
        $list = $k->handle(new Request('GET', '/api/v1/content/posts', [], [], [], ''));
        $slugs = array_column((array) (json_decode($list->body(), true)['data']['items'] ?? []), 'slug');
        truthy(!in_array($slug, $slugs, true), '被 deny 的文章仍出现在公开列表里');
    } finally {
        $db->pdo()->prepare('DELETE FROM ' . $db->prefix() . 'permission_acl WHERE resource_id = ?')
            ->execute([$slug]);
        $db->pdo()->prepare('DELETE FROM ' . $db->prefix() . 'content_posts WHERE slug = ?')->execute([$slug]);
    }
});
