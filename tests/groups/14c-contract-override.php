<?php
declare(strict_types=1);

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Contracts\DatabaseInterface;
use Wx\Contracts\LoggerInterface;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Systems\Api\ApiOperationException;

echo "\n=== 14c. 契约覆盖运行时身份鉴权：授权台账 / fail-closed / 对账 ===\n";

/** 通过受保护接口登记 / 撤销授权，返回解码后的信封 */
function overrideGrant(Kernel $k, array $headers, string $contract, string $owner, bool $remove = false): array
{
    $body = (string) json_encode([
        'contract' => $contract, 'owner' => $owner,
        'reason' => '14c 守卫用例', 'remove' => $remove,
    ], JSON_UNESCAPED_SLASHES);
    $r = $k->handle(new Request(
        'POST', '/api/v1/permission/override/grant', [], $headers, [], $body
    ));
    return (array) json_decode($r->body(), true);
}

/** 清理某契约的台账残留，保证用例可重跑 */
function clearOverrideGrant(Kernel $k, string $contract): void
{
    $db = $k->container()->resolve(DatabaseInterface::class);
    $db->pdo()->prepare(
        'DELETE FROM ' . $db->prefix() . 'permission_contract_overrides WHERE contract = ?'
    )->execute([$contract]);
}

check('默认 require_grant=true：装配期覆盖默认要求授权，系统层覆盖自动登记', function (): void {
    $r = adminKernel()->handle(new Request('GET', '/api/v1/permission/overrides', [], adminHeaders()));
    $d = (array) json_decode($r->body(), true);
    truthy(($d['code'] ?? null) === 0, '台账对账接口不可用');
    truthy(($d['data']['require_grant'] ?? null) === true, '默认应开启强制授权');
    truthy(is_array($d['data']['overrides'] ?? null) && is_array($d['data']['grants'] ?? null),
        '对账视图字段缺失');
    truthy(is_array($d['data']['unauthorized'] ?? null), '未授权清单字段缺失');
});

check('台账接口授权边界：无令牌 401 / 级别不足 40301 / 有身份但无权限 40303', function (): void {
    $anon = adminKernel()->handle(new Request('GET', '/api/v1/permission/overrides'));
    truthy($anon->status() === 401, '无令牌未返回 401');

    $low = roleKernel('author')->handle(new Request(
        'GET', '/api/v1/permission/overrides', [], roleHeaders('author')
    ));
    truthy($low->status() === 403 && (json_decode($low->body(), true)['code'] ?? null) === 40301,
        '级别不足应返回 40301');

    $k = adminKernel();
    $k->boot();
    $acl = $k->container()->resolve(AccessControlInterface::class);
    try {
        $acl->revoke('admin', '*');
        $r = $k->handle(new Request('GET', '/api/v1/permission/overrides', [], adminHeaders()));
        truthy($r->status() === 403 && (json_decode($r->body(), true)['code'] ?? null) === 40303,
            '有身份但无权限应返回 40303');
    } finally {
        $acl->grant('admin', '*');
    }
});

check('授权接口本身需要 system.contract.override 权限，且授权后台账可查、可撤销', function (): void {
    $k = adminKernel();
    $k->boot();
    clearOverrideGrant($k, LoggerInterface::class);

    // 低权限角色不能登记授权
    $low = roleKernel('author');
    $d = overrideGrant($low, roleHeaders('author'), LoggerInterface::class, 'probe:owner');
    truthy(($d['code'] ?? null) === 40301 || ($d['code'] ?? null) === 40303, '低权限不应登记授权');

    try {
        $grant = overrideGrant($k, adminHeaders(), LoggerInterface::class, 'probe:owner');
        truthy(($grant['code'] ?? null) === 0 && ($grant['data']['active'] ?? null) === true, '授权未生效');

        $view = (array) json_decode($k->handle(new Request(
            'GET', '/api/v1/permission/overrides', [], adminHeaders()
        ))->body(), true);
        $found = false;
        foreach ((array) ($view['data']['grants'] ?? []) as $g) {
            if (($g['contract'] ?? null) === LoggerInterface::class && ($g['owner'] ?? null) === 'probe:owner') {
                $found = (int) ($g['active'] ?? 0) === 1;
            }
        }
        truthy($found, '台账未查到授权记录');

        $rm = overrideGrant($k, adminHeaders(), LoggerInterface::class, 'probe:owner', true);
        truthy(($rm['code'] ?? null) === 0 && ($rm['data']['active'] ?? null) === false, '撤销未生效');
    } finally {
        clearOverrideGrant($k, LoggerInterface::class);
    }
});

check('纵深防御：绕过 RequestGuard 直调执行者、无 _identity 时拒绝授权', function (): void {
    $k = adminKernel();
    $k->boot();
    /** @var ApiRegistryInterface $registry */
    $registry = $k->container()->resolve(ApiRegistryInterface::class);
    $threw = false;
    try {
        $registry->execute('permission.override.grant', [
            'contract' => LoggerInterface::class, 'owner' => 'probe',
        ]);
    } catch (ApiOperationException $e) {
        $threw = $e->apiCode === 40303;
    }
    truthy($threw, '执行者未复核身份，存在无主授权后门');
});

check('require_grant=true 且已登记授权：装配期覆盖放行并留痕 grant=ok', function () use ($root): void {
    $k0 = adminKernel();
    $k0->boot();
    clearOverrideGrant($k0, LoggerInterface::class);
    try {
        $grant = overrideGrant($k0, adminHeaders(), LoggerInterface::class, 'probe:ok');
        truthy(($grant['code'] ?? null) === 0, '预登记授权失败');

        $seen = [];
        $k = new Kernel($root, [
            'api' => ['tokens' => ['test-admin-token' => [
                'role' => 'admin', 'username' => 'admin', 'user_id' => 1,
            ]]],
            'permission' => ['contract_override' => ['require_grant' => true]],
        ]);
        $k->hooks()->on('permission.decision', static function (array $e) use (&$seen): void {
            $seen[] = $e;
        }, 99);
        $k->boot();
        $k->container()->singleton(
            LoggerInterface::class, static fn(): object => new stdClass(), 'probe:ok', 99
        );
        truthy(count($k->container()->overrides()) >= 1, '已授权覆盖未生效');
        truthy((bool) array_filter($seen, static fn(array $e): bool =>
            str_contains((string) ($e['reason'] ?? ''), 'grant=ok')), '留痕未标注授权通过');
    } finally {
        clearOverrideGrant($k0, LoggerInterface::class);
    }
});

check('require_grant=true 且未登记授权：覆盖 fail-closed 拒绝，容器不写入脏实现', function () use ($root): void {
    $k = new Kernel($root, ['permission' => ['contract_override' => ['require_grant' => true]]]);
    $k->boot();
    $threw = false;
    try {
        $k->container()->singleton(
            LoggerInterface::class, static fn(): object => new stdClass(), 'probe:missing', 99
        );
    } catch (RuntimeException $e) {
        $threw = str_contains($e->getMessage(), '契约覆盖被权限策略拒绝');
    }
    truthy($threw, '未授权覆盖未被拒绝');
    truthy($k->container()->ownerOf(LoggerInterface::class) !== 'probe:missing', '被拒覆盖仍写入了容器');
});

check('拔 DSN + require_grant=true：台账不可读仍 fail-closed，公开页不锁死', function () use ($root): void {
    $k = new Kernel($root, [
        'database' => ['dsn' => ''],
        'permission' => ['contract_override' => ['require_grant' => true]],
    ]);
    $k->boot();
    $threw = false;
    try {
        $k->container()->singleton(
            LoggerInterface::class, static fn(): object => new stdClass(), 'probe:fallback', 99
        );
    } catch (RuntimeException) { $threw = true; }
    truthy($threw, '拔库时未 fail-closed（台账不可读不得静默放行）');
    truthy(str_contains($k->handle(new Request('GET', '/'))->body(), '站点首页'), '拔库后公开首页被锁死');
});

check('卸载清理 9 张权限表、分组表不遗漏；新接口已四方对账', function () use ($root): void {
    $src = (string) file_get_contents($root . '/systems/Permission/lifecycle.php');
    foreach (['permission_audit', 'permission_acl', 'permission_contract_overrides',
              'permission_grants', 'permission_group_roles', 'permission_group_subjects',
              'permission_groups', 'permission_role_parents', 'permission_roles'] as $t) {
        truthy(str_contains($src, $t), '卸载钩子遗漏 ' . $t);
    }
    $d = json_decode((string) file_get_contents($root . '/api/registry.json'), true);
    $ids = array_column($d['endpoints'], 'id');
    truthy(in_array('API-PERM-011', $ids, true) && in_array('API-PERM-012', $ids, true), 'v0.8 接口未登记');
    truthy(in_array('API-PERM-013', $ids, true) && in_array('API-PERM-014', $ids, true), 'v0.9 分组接口未登记');
    truthy(count($d['endpoints']) === 67, '接口总数异常');
    truthy(is_file($root . '/docs/api/API-PERM-011.md') && is_file($root . '/docs/api/API-PERM-012.md'),
        '新接口操作文档缺失');
});
