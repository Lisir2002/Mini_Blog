<?php
declare(strict_types=1);

use Wx\Contracts\ContentRepositoryInterface;
use Wx\Contracts\DatabaseInterface;
use Wx\Contracts\LoggerInterface;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Systems\Permission\DenyAllAccessControl;
use Wx\Systems\Permission\PermissionRegistry;

echo "\n=== 13b. 权限协作：决策点全覆盖 / 布局权归主题 ===\n";

/** 造一篇可重跑的测试文章；已存在则复用，避免污染。 */
function permPost(Kernel $k, string $slug, string $status, string $author): void
{
    $repo = $k->container()->resolve(ContentRepositoryInterface::class);
    if ($repo->findBySlug($slug) !== null) return;
    $repo->create([
        'slug' => $slug, 'title' => '权限用例 ' . $slug, 'body' => '用于权限守卫的可重跑数据。',
        'status' => $status, 'author' => $author,
    ]);
}

/** @return list<string> 取 content.posts 返回的文章 slug 列表 */
function visibleSlugs(Kernel $k, array $headers = []): array
{
    $r = $k->handle(new Request('GET', '/api/v1/content/posts', ['limit' => '100', 'status' => 'any'], $headers));
    $items = json_decode($r->body(), true)['data']['items'] ?? [];
    return array_map(static fn(array $p): string => (string) $p['slug'], $items);
}

check('接口清单的 permission 字段与权限声明逐条对账', function () use ($root): void {
    $reg = new PermissionRegistry($root);
    $data = json_decode((string) file_get_contents($root . '/api/registry.json'), true);
    $bound = 0;
    foreach ($data['endpoints'] as $endpoint) {
        if (!isset($endpoint['permission'])) continue;
        $bound++;
        truthy($reg->has((string) $endpoint['permission']),
            '接口 ' . $endpoint['id'] . ' 绑定了未声明的权限：' . $endpoint['permission']);
        truthy(in_array(40303, (array) ($endpoint['errors'] ?? []), true),
            '接口 ' . $endpoint['id'] . ' 的错误码表缺少 40303');
    }
    truthy($bound >= 15, '受权限约束的接口过少，守卫可能失效：' . $bound);
});

check('API 层权限从声明变执行：无令牌 401，撤销许可后 40303', function (): void {
    $anon = kernel(null)->handle(new Request('GET', '/api/v1/lifecycle/units'));
    truthy($anon->status() === 401, '无令牌未返回 401');

    $k = adminKernel();
    $k->boot();
    $acl = $k->container()->resolve(Wx\Contracts\AccessControlInterface::class);
    $ok = json_decode($k->handle(new Request('GET', '/api/v1/lifecycle/units', [], adminHeaders()))->body(), true);
    truthy(($ok['code'] ?? null) === 0, '有令牌时未放行');

    try {
        $acl->revoke('admin', '*');
        $r = $k->handle(new Request('GET', '/api/v1/lifecycle/units', [], adminHeaders()));
        $body = json_decode($r->body(), true);
        truthy($r->status() === 403 && ($body['code'] ?? null) === 40303,
            '撤销许可后未按 fail-closed 返回 40303，实际：' . ($body['code'] ?? '?'));
    } finally {
        $acl->grant('admin', '*');
    }
});

check('permission.check 接口：匿名 denied / admin allowed', function (): void {
    $k = adminKernel();
    $anon = json_decode($k->handle(new Request('GET', '/api/v1/permission/check', ['action' => 'content.delete']))->body(), true);
    truthy(($anon['data']['decision'] ?? null) === 'abstain' && ($anon['data']['allowed'] ?? null) === false,
        '匿名决策不正确');
    $ok = json_decode($k->handle(new Request('GET', '/api/v1/permission/check', ['action' => 'content.delete'], adminHeaders()))->body(), true);
    truthy(($ok['data']['decision'] ?? null) === 'allow' && ($ok['data']['allowed'] ?? null) === true,
        'admin 决策不正确');
});

check('文章权限贯通到列表：显式 deny 的已发布文章被剔除', function () use ($root): void {
    $k = new Kernel($root);
    $k->boot();
    $slug  = 'perm-visible-case';
    $acl   = $k->container()->resolve(Wx\Contracts\AccessControlInterface::class);
    $db    = $k->container()->resolve(DatabaseInterface::class);
    $table = $db->prefix() . 'permission_acl';
    $clean = static function () use ($db, $table, $slug): void {
        $db->pdo()->prepare('DELETE FROM ' . $table . ' WHERE resource_id = ?')->execute([$slug]);
    };

    $clean();                       // 先清残留，保证基线可重跑
    permPost($k, $slug, 'published', 'admin');
    try {
        truthy(in_array($slug, visibleSlugs($k), true), '基线列表缺少测试文章');
        $acl->setAcl([
            'type' => 'post', 'id' => $slug, 'subject' => '*',
            'capability' => 'content.read', 'allow' => false,
        ]);
        truthy(!in_array($slug, visibleSlugs($k), true), '显式 deny 未从列表剔除文章');
    } finally {
        $clean();
    }
});

check('public 接口可选携带身份：作者可见自己的草稿，匿名不可见', function () use ($root): void {
    $k = new Kernel($root, ['api' => ['tokens' => [
        't-author-token' => ['role' => 'author', 'username' => 'alice', 'user_id' => 7],
    ]]]);
    $k->boot();
    $slug = 'perm-draft-case';
    permPost($k, $slug, 'draft', 'alice');

    truthy(!in_array($slug, visibleSlugs($k), true), '匿名看到了他人草稿');
    truthy(in_array($slug, visibleSlugs($k, ['Authorization' => 'Bearer t-author-token']), true),
        '作者看不到自己的草稿（public 接口未注入可选身份）');
});

check('契约覆盖裁决点被询问并留痕（系统权限）', function () use ($root): void {
    $k = contractKernel(null);
    $k->boot();
    $seen = [];
    $k->hooks()->filter('contract.override', static function (bool $v, string $contract) use (&$seen): bool {
        $seen[] = $contract; return $v;
    }, 90);
    $k->container()->singleton(
        LoggerInterface::class,
        static fn(): object => new stdClass(), 'test:override', 99
    );
    truthy($seen !== [], '契约覆盖未经过 contract.override 裁决点');
    truthy(count($k->container()->overrides()) >= 1, '覆盖未留痕');
});

check('拔 DSN 回退为 DenyAll：fail-closed 但公开只读不锁死', function () use ($root): void {
    $k = new Kernel($root, ['database' => ['dsn' => '']]);
    $k->boot();
    $acl = $k->container()->resolve(Wx\Contracts\AccessControlInterface::class);
    truthy($acl instanceof DenyAllAccessControl, '未回退到全拒绝实现');
    truthy(!$acl->allows(['role' => 'admin'], 'content.delete'), '拔库后仍放行了写操作');
    truthy($acl->catalog() !== [], '拔库后权限清单不应丢失');
    $r = $k->handle(new Request('GET', '/'));
    truthy(str_contains($r->body(), '站点首页'), '拔库后公开首页被锁死');
});

check('规矩 J：render.layout 是可编程裁决点，主题自报布局槽位', function () use ($root): void {
    $k = pageKernel('bare');
    $k->boot();
    $seen = [];
    $k->hooks()->filter('render.layout', static function (string $layout) use (&$seen): string {
        $seen[] = $layout; return $layout;
    }, 90);
    $k->handle(new Request('GET', '/console', [], adminHeaders()));
    truthy(in_array('admin', $seen, true), '后台未经过 render.layout 决议');
    $declared = (array) $k->hooks()->apply('theme.layouts', []);
    truthy(in_array('admin', $declared, true), '主题未自报 admin 布局');
    $r = pageKernel('bare-html')->handle(new Request('GET', '/console', [], adminHeaders()));
    truthy(str_contains($r->contentType(), 'json'), '主题缺 admin 布局时未退化为纯数据');
});
