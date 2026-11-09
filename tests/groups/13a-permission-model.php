<?php
declare(strict_types=1);

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\DatabaseInterface;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Systems\Lifecycle\Fs;
use Wx\Systems\Permission\DenyAllAccessControl;
use Wx\Systems\Permission\PermissionRegistry;

echo "\n=== 13a. 权限模型：三层权限 / 三态决策 / fail-closed ===\n";

$admin  = ['user_id' => 1, 'username' => 'admin', 'role' => 'admin'];
$author = ['user_id' => 2, 'username' => 'alice', 'role' => 'author'];
$editor = ['user_id' => 3, 'username' => 'bob', 'role' => 'editor'];

/** @return array{0: Kernel, 1: AccessControlInterface} */
function permKernel(): array
{
    global $root;
    $k = new Kernel($root);
    $k->boot();
    return [$k, $k->container()->resolve(AccessControlInterface::class)];
}

check('权限点由各系统层自报，核心零权限名', function () use ($root): void {
    $reg = new PermissionRegistry($root);
    truthy(count($reg->all()) === 31, '权限点数量异常：' . count($reg->all()));
    truthy(($reg->bySystem()['page'] ?? 0) === 3, '页面层权限点未自报 3 个');
    $by = $reg->bySystem();
    foreach (['content', 'lifecycle', 'permission', 'user', 'log'] as $system) {
        truthy(isset($by[$system]), '系统层未自报权限：' . $system);
    }
    foreach (['content.delete', 'lifecycle.install', 'system.contract.override', 'logs.read'] as $name) {
        truthy($reg->has($name), '缺少权限点：' . $name);
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/core', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') continue;
        $src = (string) file_get_contents($file->getPathname());
        truthy(!str_contains($src, 'content.delete') && !str_contains($src, 'lifecycle.install'),
            '核心硬编码了具体权限名：' . $file->getFilename());
    }
});

check('决策三态：未声明 / 匿名 / 无策略一律 abstain，allows 恒为 false', function () use ($admin): void {
    [, $acl] = permKernel();
    truthy($acl->decide($admin, 'no.such.perm') === AccessControlInterface::ABSTAIN, '未声明权限未返回 abstain');
    truthy($acl->decide(null, 'content.delete') === AccessControlInterface::ABSTAIN, '匿名主体未返回 abstain');
    truthy($acl->allows(null, 'content.delete') === false, '匿名主体被放行');
    $ghost = ['user_id' => 9, 'username' => 'ghost', 'role' => 'ghost'];
    truthy($acl->decide($ghost, 'content.delete') === AccessControlInterface::ABSTAIN, '无授权角色未返回 abstain');
});

check('用户权限（RBAC）：角色许可、前缀通配，且 * 是显式可撤销的', function () use ($admin, $author, $editor): void {
    [, $acl] = permKernel();
    truthy($acl->allows($admin, 'content.delete'), 'admin 通配符未生效');
    truthy($acl->allows($editor, 'content.publish'), 'editor 的 content.* 未生效');
    truthy(!$acl->allows($author, 'content.publish'), 'author 不应有发布权限');
    try {
        $acl->revoke('admin', '*');
        truthy(!$acl->allows($admin, 'content.delete'), '撤销 * 后 admin 仍有隐式全权');
    } finally {
        $acl->grant('admin', '*');
    }
    truthy($acl->allows($admin, 'content.delete'), '恢复通配符失败，测试污染了数据');
});

check('文章权限（资源 ACL）：显式 deny 压过角色通配符', function () use ($admin, $root): void {
    [$k, $acl] = permKernel();
    $resource = ['type' => 'post', 'id' => 'perm-deny-case', 'status' => 'published', 'author' => 'admin'];
    $db = $k->container()->resolve(DatabaseInterface::class);
    $table = $db->prefix() . 'permission_acl';
    $db->pdo()->prepare('DELETE FROM ' . $table . ' WHERE resource_id = ?')->execute(['perm-deny-case']);
    truthy($acl->allows($admin, 'content.delete', $resource), '基线应放行');
    $acl->setAcl([
        'type' => 'post', 'id' => 'perm-deny-case', 'subject' => 'role:admin',
        'capability' => 'content.delete', 'allow' => false,
    ]);
    truthy($acl->decide($admin, 'content.delete', $resource) === AccessControlInterface::DENY,
        '显式 deny 未压过通配符 *');
    $db->pdo()->prepare('DELETE FROM ' . $table . ' WHERE resource_id = ?')->execute(['perm-deny-case']);
});

check('策略钩子只能收紧，不能借扩展点提权', function () use ($admin, $root): void {
    // 提权尝试：base=abstain，钩子返回 allow → 必须仍为 abstain
    $k1 = new Kernel($root);
    $k1->boot();
    $k1->hooks()->filter('permission.decide',
        static fn(string $d): string => AccessControlInterface::ALLOW, 50);
    $acl1 = $k1->container()->resolve(AccessControlInterface::class);
    $ghost = ['user_id' => 9, 'username' => 'ghost', 'role' => 'ghost'];
    truthy($acl1->decide($ghost, 'content.delete') === AccessControlInterface::ABSTAIN,
        '策略钩子成功提权，这是后门');

    // 收紧：base=allow，钩子返回 deny → deny
    $k2 = new Kernel($root);
    $k2->boot();
    $k2->hooks()->filter('permission.decide',
        static fn(string $d): string => AccessControlInterface::DENY, 50);
    $acl2 = $k2->container()->resolve(AccessControlInterface::class);
    truthy($acl2->decide($admin, 'content.delete') === AccessControlInterface::DENY,
        '策略钩子未能收紧已有授权');
});

check('每次决策都留痕：写 audit 表并 emit permission.decision 事件', function (): void {
    [$k, $acl] = permKernel();
    $seen = 0;
    $k->hooks()->on('permission.decision', static function () use (&$seen): void { $seen++; }, 90);
    $db = $k->container()->resolve(DatabaseInterface::class);
    $table = $db->prefix() . 'permission_audit';
    $before = (int) $db->query('SELECT COUNT(*) AS c FROM ' . $table)[0]['c'];
    $acl->decide(null, 'content.delete');
    truthy($seen >= 1, '未 emit 决策事件');
    $after = (int) $db->query('SELECT COUNT(*) AS c FROM ' . $table)[0]['c'];
    truthy($after === $before + 1, '决策未写入 audit 表');
});

check('权限声明冲突 fail-fast，不静默后者覆盖前者', function (): void {
    $tmp = sys_get_temp_dir() . '/wx-perm-' . bin2hex(random_bytes(4));
    try {
        foreach (['A', 'B'] as $dir) {
            mkdir($tmp . '/systems/' . $dir, 0775, true);
            file_put_contents($tmp . '/systems/' . $dir . '/permissions.json', json_encode([
                'system' => $dir, 'permissions' => [['name' => 'duplicate.action', 'description' => 'x']],
            ]));
        }
        $threw = false;
        try { new PermissionRegistry($tmp); }
        catch (RuntimeException $e) { $threw = str_contains($e->getMessage(), '冲突'); }
        truthy($threw, '同名权限声明未触发 fail-fast');
    } finally {
        Fs::removeDir($tmp);
    }
});

check('契约可替换：换成全拒绝实现后受保护操作全拒，公开只读不锁死', function () use ($root, $admin): void {
    $k = contractKernel(null);
    $k->boot();
    $k->container()->singleton(
        AccessControlInterface::class,
        static fn(): DenyAllAccessControl => new DenyAllAccessControl(new PermissionRegistry($root)),
        'test:deny-all', 99
    );
    $acl = $k->container()->resolve(AccessControlInterface::class);
    truthy($acl instanceof DenyAllAccessControl, '替换未生效');
    truthy(!$acl->allows($admin, 'content.delete'), '全拒绝实现却放行了写操作');
    truthy($acl->catalog() !== [], '全拒绝实现不应丢失权限清单');
    $r = $k->handle(new Request('GET', '/'));
    truthy(str_contains($r->body(), '第一篇文章'), '替换实现后公开首页被锁死');
});
