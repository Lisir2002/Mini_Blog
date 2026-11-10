<?php
declare(strict_types=1);

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Contracts\DatabaseInterface;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Systems\Permission\AccessController;
use Wx\Systems\Permission\PermissionRegistry;

echo "\n=== 13d. 决策缓存 / 字段级权限 / 协作对账 ===\n";

/** @return array{0: Kernel, 1: AccessControlInterface} */
function fldKernel(array $extra = []): array
{
    global $root;
    $k = new Kernel($root, $extra);
    $k->boot();
    return [$k, $k->container()->resolve(AccessControlInterface::class)];
}

function fldClean(Kernel $k, string $id): void
{
    $db = $k->container()->resolve(DatabaseInterface::class);
    $db->pdo()->prepare('DELETE FROM ' . $db->prefix() . 'permission_acl WHERE resource_id = ?')->execute([$id]);
}

function fldAdmin(): array
{
    return ['user_id' => 1, 'username' => 'admin', 'role' => 'admin'];
}

check('决策缓存：重复决策命中，授权变更显式失效并重新读库', function (): void {
    [, $acl] = fldKernel();
    truthy($acl instanceof AccessController, '守卫需要 MySQL 权限实现');
    $res = ['type' => 'post', 'id' => 'perm-cache-case'];
    $before = $acl->cacheStats();
    $acl->decide(fldAdmin(), 'content.delete', $res);
    $acl->decide(fldAdmin(), 'content.delete', $res);
    $after = $acl->cacheStats();
    truthy($after['enabled'] === 1, '缓存默认未启用');
    truthy($after['hits'] > $before['hits'], '重复决策未命中缓存');
    $acl->grant('editor', 'logs.read');
    $flushed = $acl->cacheStats();
    truthy($flushed['flushes'] > $after['flushes'], '授权变更未触发失效');
    truthy($flushed['size'] === 0, '失效后缓存未清空');
    $acl->decide(fldAdmin(), 'content.delete', $res);
    truthy($acl->cacheStats()['misses'] > $after['misses'], '失效后未重新读库');
});

check('缓存绝不绕过策略钩子：最终决策每次重新裁决', function (): void {
    [$k, $acl] = fldKernel();
    truthy($acl->allows(fldAdmin(), 'content.delete'), '基线应放行');
    $seen = 0;
    $k->hooks()->filter('permission.decide', static function (string $d) use (&$seen): string {
        $seen++;
        return AccessControlInterface::DENY;
    }, 50);
    for ($i = 0; $i < 3; $i++) {
        truthy($acl->decide(fldAdmin(), 'content.delete') === AccessControlInterface::DENY,
            '第 ' . ($i + 1) . ' 次决策未应用策略收紧');
    }
    truthy($seen === 3, '策略钩子未每次执行，说明最终决策被缓存');
});

check('缓存开关：关闭后 hits 恒为 0，且决策语义完全一致', function (): void {
    [, $on]  = fldKernel();
    [, $off] = fldKernel(['permission' => ['cache' => ['enabled' => false]]]);
    truthy($off instanceof AccessController, '守卫需要 MySQL 权限实现');
    truthy($off->cacheStats()['enabled'] === 0, '配置开关未生效');
    $ghost = ['user_id' => 9, 'username' => 'ghost', 'role' => 'ghost'];
    foreach ([[fldAdmin(), 'content.delete'], [$ghost, 'content.delete'], [null, 'content.delete']] as [$id, $action]) {
        truthy($on->decide($id, $action) === $off->decide($id, $action),
            '开关前后决策不一致：' . $action);
    }
    truthy($off->cacheStats()['hits'] === 0, '关闭缓存后仍有命中');
});

check('字段级权限：field 精确 deny，整资源级条目对任意字段生效', function (): void {
    [$k, $acl] = fldKernel();
    $id = 'perm-field-case';
    $base = ['type' => 'post', 'id' => $id, 'status' => 'published', 'author' => 'admin'];
    fldClean($k, $id);
    try {
        $acl->setAcl([
            'type' => 'post', 'id' => $id, 'subject' => '*',
            'capability' => 'content.read', 'allow' => false, 'field' => 'body',
        ]);
        truthy($acl->decide(fldAdmin(), 'content.read', $base + ['field' => 'body'])
            === AccessControlInterface::DENY, '字段级 deny 未生效');
        truthy($acl->decide(fldAdmin(), 'content.read', $base + ['field' => 'title'])
            !== AccessControlInterface::DENY, '字段级 deny 误伤了其它字段');
        truthy($acl->decide(fldAdmin(), 'content.read', $base)
            !== AccessControlInterface::DENY, '不带 field 时字段级条目不应生效');

        $acl->setAcl([
            'type' => 'post', 'id' => $id, 'subject' => '*',
            'capability' => 'content.read', 'allow' => false, 'field' => '',
        ]);
        truthy($acl->decide(fldAdmin(), 'content.read', $base + ['field' => 'title'])
            === AccessControlInterface::DENY, '整资源级 deny 未覆盖任意字段');
        truthy($acl->decide(null, 'content.read', $base + ['field' => 'body'])
            === AccessControlInterface::DENY, '整资源级 deny 对匿名未生效');
    } finally {
        fldClean($k, $id);
    }
});

check('字段级权限贯通到接口：acl.set 写入 field，permission.check 按字段裁决', function (): void {
    $k = adminKernel();
    $k->boot();
    $id = 'perm-field-http-case';
    fldClean($k, $id);
    try {
        $set = json_decode($k->handle(new Request(
            'POST', '/api/v1/permission/acl', [], adminHeaders(), [], json_encode([
                'type' => 'post', 'id' => $id, 'subject' => '*',
                'capability' => 'content.read', 'allow' => false, 'field' => 'body',
            ])
        ))->body(), true);
        truthy(($set['code'] ?? null) === 0, '带 field 的 ACL 写入失败');

        $query = static function (Kernel $k, string $field): array {
            return json_decode($k->handle(new Request(
                'GET', '/api/v1/permission/check',
                ['action' => 'content.read', 'resource_type' => 'post',
                 'resource_id' => 'perm-field-http-case', 'field' => $field],
                adminHeaders()
            ))->body(), true);
        };
        truthy(($query($k, 'body')['data']['decision'] ?? null) === 'deny', 'body 字段未被拒绝');
        truthy(($query($k, 'title')['data']['decision'] ?? null) === 'allow', 'title 字段被误拒');
    } finally {
        fldClean($k, $id);
    }
});

check('拔 DSN 回退：受保护接口 fail-closed，执行者优雅回退，公开只读不锁死', function (): void {
    $k = adminKernel(['database' => ['dsn' => '']]);
    $k->boot();
    // HTTP 层：受权限约束的 admin 接口必须被 40303 拒绝，不能抛异常或伪造数据
    $r = $k->handle(new Request('GET', '/api/v1/permission/role/tree', [], adminHeaders()));
    $body = json_decode($r->body(), true);
    truthy($r->status() === 403 && ($body['code'] ?? null) === 40303,
        '拔库后受保护接口未按 fail-closed 拒绝（40303）');

    // 执行者本体：绕过 RequestGuard 直接调用，验证回退实现不会 500、不伪造继承图
    $registry = $k->container()->resolve(ApiRegistryInterface::class);
    $tree = $registry->execute('permission.role.tree', []);
    truthy(($tree['roles'] ?? null) === [], '回退执行者伪造了继承图');
    truthy(in_array('fallback', $tree['warnings'] ?? [], true), '回退执行者未带告警');
    $decisions = $registry->execute('permission.decisions', []);
    truthy(($decisions['cache'] ?? null) === [], '回退时不应报告决策缓存');

    truthy(str_contains($k->handle(new Request('GET', '/'))->body(), '站点首页'),
        '拔库后公开首页被锁死');
});

check('协作对账：88 个 operation 全绑定，新增协作者零违规零孤儿', function () use ($root): void {
    $report = json_decode((string) file_get_contents($root . '/docs/responsibility-report.json'), true);
    $manifest = json_decode((string) file_get_contents($root . '/api/registry.json'), true);
    $count = count($manifest['endpoints']);
    $summary = $report['collaboration']['summary'];
    truthy($summary['operations'] === $count && $summary['operations_bound'] === $count,
        '清单 operation 与执行者未 1:1');
    truthy($report['collaboration']['orphans'] === [], '仍存在孤儿协作');
    foreach (['systems/Permission/RoleGraph.php', 'systems/Permission/PermissionCache.php',
              'systems/Permission/AclMatcher.php'] as $rel) {
        $hit = false;
        foreach ($report['files'] as $f) {
            if ($f['path'] === $rel) {
                $hit = true;
                truthy($f['violations'] === [], $rel . ' 单文件职责超预算');
            }
        }
        truthy($hit, '审计器未收录新增协作者：' . $rel);
    }
    $perms = new PermissionRegistry($root);
    truthy(count($perms->all()) === 48, '权限点总数异常');
    truthy(($perms->bySystem()['page'] ?? 0) === 3, 'v0.8 页面权限点应由 Page 层自报');
});
