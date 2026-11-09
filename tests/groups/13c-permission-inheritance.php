<?php
declare(strict_types=1);

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\DatabaseInterface;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Systems\Permission\AccessController;
use Wx\Systems\Permission\RoleGraph;

echo "\n=== 13c. 角色继承：并集语义 / 环路裁决 / 撤销传播 ===\n";

/** @return array{0: Kernel, 1: AccessController} */
function inhKernel(): array
{
    global $root;
    $k = new Kernel($root);
    $k->boot();
    $acl = $k->container()->resolve(AccessControlInterface::class);
    truthy($acl instanceof AccessController, '守卫需要 MySQL 权限实现');
    return [$k, $acl];
}

/** 精确移除本组造出的继承边，保证守卫可重跑、不污染站点授权。 */
function inhDetach(AccessController $acl, array $pairs): void
{
    foreach ($pairs as [$role, $parent]) $acl->setParent($role, $parent, true);
}

function inhAclClean(Kernel $k, string $id): void
{
    $db = $k->container()->resolve(DatabaseInterface::class);
    $db->pdo()->prepare('DELETE FROM ' . $db->prefix() . 'permission_acl WHERE resource_id = ?')->execute([$id]);
}

function subIdentity(): array
{
    return ['user_id' => 9, 'username' => 'sub', 'role' => 'subscriber'];
}

check('角色继承：子角色获得父角色许可，自身许可不丢失', function (): void {
    [, $acl] = inhKernel();
    $pairs = [['subscriber', 'author']];
    try {
        truthy(!$acl->allows(subIdentity(), 'content.create'), '基线：subscriber 不应能创建文章');
        $r = $acl->setParent('subscriber', 'author');
        truthy($r['ok'] && $r['changed'], '继承关系未写入');
        truthy($acl->allows(subIdentity(), 'content.create'), '继承后未获得父角色许可');
        truthy($acl->allows(subIdentity(), 'content.read'), '继承后自身许可丢失');
    } finally {
        inhDetach($acl, $pairs);
    }
});

check('多层继承：祖先链按广度优先展开，有效许可为自身与全部祖先的并集', function (): void {
    [, $acl] = inhKernel();
    $pairs = [['subscriber', 'author'], ['author', 'editor']];
    try {
        $acl->setParent('subscriber', 'author');
        $acl->setParent('author', 'editor');
        $graph = $acl->graph();
        truthy($graph->ancestors('subscriber') === ['author', 'editor'], '祖先链未按广度优先展开');
        $effective = $graph->effectivePermissions('subscriber');
        foreach (['content.*', 'logs.read', 'content.create', 'content.edit'] as $perm) {
            truthy(in_array($perm, $effective, true), '有效许可缺少：' . $perm);
        }
        $tree = $acl->roleTree();
        truthy($tree['max_depth'] === RoleGraph::MAX_DEPTH, '继承深度上限未暴露');
        truthy($tree['roles']['subscriber']['parents'] === ['author'], '继承图未反映直接父角色');
        truthy($acl->allows(subIdentity(), 'content.publish'), '三层继承未贯通到 editor 的 content.*');
    } finally {
        inhDetach($acl, $pairs);
    }
});

check('继承写入裁决：自环 / 成环一律拒绝且不写库', function (): void {
    [, $acl] = inhKernel();
    $pairs = [['subscriber', 'author'], ['author', 'editor']];
    try {
        $self = $acl->setParent('author', 'author');
        truthy(!$self['ok'] && $self['reason'] === 'self' && !$self['changed'], '自环未被拒绝');
        truthy(!in_array('author', $acl->graph()->parentsFor('author'), true), '自环被写进了库');

        $acl->setParent('subscriber', 'author');
        $acl->setParent('author', 'editor');
        $cycle = $acl->setParent('editor', 'subscriber');
        truthy(!$cycle['ok'] && $cycle['reason'] === 'cycle' && !$cycle['changed'], '成环未被拒绝');
        truthy(!in_array('subscriber', $acl->graph()->parentsFor('editor'), true), '成环关系被写进了库');
    } finally {
        inhDetach($acl, $pairs);
    }
});

check('继承深度上限：超深链被截断并留痕，不会无限递归', function (): void {
    [$k, $acl] = inhKernel();
    $db = $k->container()->resolve(DatabaseInterface::class);
    $table = $db->prefix() . 'permission_role_parents';
    $pdo = $db->pdo();
    $stmt = $pdo->prepare('INSERT IGNORE INTO ' . $table . ' (role,parent_role,created_at) VALUES (?,?,?)');
    try {
        for ($i = 0; $i < 9; $i++) {
            $stmt->execute(['guard-depth-' . $i, 'guard-depth-' . ($i + 1), date('Y-m-d H:i:s')]);
        }
        $graph = $acl->graph();
        $ancestors = $graph->ancestors('guard-depth-0');
        truthy(count($ancestors) === RoleGraph::MAX_DEPTH,
            '超深链未按上限截断，实际 ' . count($ancestors) . ' 层');
        truthy(in_array('depth_limit:' . RoleGraph::MAX_DEPTH, $graph->warnings(), true),
            '深度截断未留痕');
        $deep = $graph->canSetParent('guard-depth-9', 'guard-depth-0');
        truthy($deep['reason'] === 'cycle' || $deep['reason'] === 'depth',
            '超深链末端未给出明确拒绝原因');
    } finally {
        $pdo->prepare('DELETE FROM ' . $table . ' WHERE role LIKE ?')->execute(['guard-depth-%']);
    }
});

check('撤销父角色许可后子角色同步失去：授权变更显式失效缓存', function (): void {
    [, $acl] = inhKernel();
    $pairs = [['subscriber', 'author']];
    $acl->setParent('subscriber', 'author');
    try {
        truthy($acl->allows(subIdentity(), 'content.create'), '继承基线未生效');
        truthy($acl->revoke('author', 'content.create'), '撤销未生效');
        truthy(!$acl->allows(subIdentity(), 'content.create'), 'revoke 后仍读到过期授权');
    } finally {
        $acl->grant('author', 'content.create');
        inhDetach($acl, $pairs);
    }
});

check('显式 deny 仍压过继承来的许可，且对匿名同样生效', function (): void {
    [$k, $acl] = inhKernel();
    $pairs = [['subscriber', 'author']];
    $id = 'perm-inh-deny-case';
    $resource = ['type' => 'post', 'id' => $id, 'status' => 'published', 'author' => 'admin'];
    inhAclClean($k, $id);
    $acl->setParent('subscriber', 'author');
    try {
        $acl->setAcl([
            'type' => 'post', 'id' => $id, 'subject' => '*',
            'capability' => 'content.read', 'allow' => false, 'field' => '',
        ]);
        truthy($acl->decide(null, 'content.read', $resource) === AccessControlInterface::DENY,
            '匿名未被显式 deny 拦住');
        truthy($acl->decide(subIdentity(), 'content.read', $resource) === AccessControlInterface::DENY,
            '继承来的许可压过了显式 deny');
    } finally {
        inhAclClean($k, $id);
        inhDetach($acl, $pairs);
    }
});

check('策略钩子仍可收紧继承来的许可，继承不构成提权后门', function () use ($root): void {
    $k = new Kernel($root);
    $k->boot();
    $acl = $k->container()->resolve(AccessControlInterface::class);
    $k->hooks()->filter('permission.decide',
        static fn(string $d): string => AccessControlInterface::DENY, 50);
    $pairs = [['subscriber', 'author']];
    $acl->setParent('subscriber', 'author');
    try {
        truthy($acl->decide(subIdentity(), 'content.create') === AccessControlInterface::DENY,
            '策略收紧未能压过继承许可');
    } finally {
        inhDetach($acl, $pairs);
    }
});

check('接口贯通：继承图查询 / 父级写入 / 成环返回 40901', function (): void {
    $k = adminKernel();
    $k->boot();
    $tree = json_decode($k->handle(new Request(
        'GET', '/api/v1/permission/role/tree', [], adminHeaders()
    ))->body(), true);
    truthy(($tree['code'] ?? null) === 0, '继承图接口未返回成功');
    truthy(isset($tree['data']['roles']['admin']), '继承图缺少默认角色');
    truthy(($tree['data']['max_depth'] ?? 0) === RoleGraph::MAX_DEPTH, '接口未暴露深度上限');

    $post = static function (Kernel $k, array $body): array {
        return json_decode($k->handle(new Request(
            'POST', '/api/v1/permission/role/parent', [], adminHeaders(), [], json_encode($body)
        ))->body(), true);
    };
    $pairs = [['subscriber', 'author']];
    try {
        $added = $post($k, ['role' => 'subscriber', 'parent' => 'author']);
        truthy(($added['code'] ?? null) === 0 && ($added['data']['changed'] ?? false), '父级写入未生效');
        $again = json_decode($k->handle(new Request(
            'GET', '/api/v1/permission/role/tree', [], adminHeaders()
        ))->body(), true);
        truthy(in_array('author', $again['data']['roles']['subscriber']['parents'] ?? [], true),
            '写入后继承图未更新');
        $cycle = $post($k, ['role' => 'author', 'parent' => 'subscriber']);
        truthy(($cycle['code'] ?? null) === 40901, '成环未按 40901 拒绝');
    } finally {
        $post($k, ['role' => 'subscriber', 'parent' => 'author', 'remove' => true]);
    }
});
