<?php
declare(strict_types=1);
namespace Wx\Systems\Permission;

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\AbstractSystem;
use Wx\Core\Kernel;
use Wx\Systems\Api\ApiOperationException;
use Wx\Systems\Data\Db;

/**
 * 权限系统 —— 第 12 个系统层，横切所有系统层的授权决策面。
 *
 * 三层权限：用户权限（角色→许可，v0.7 支持角色继承）、文章权限（资源 ACL + 状态，
 * v0.7 支持 field 维度）、系统权限（操作→许可，各层 permissions.json 自报）。
 * 本层只做装配与接口登记；决策在 AccessController，图结构在 RoleGraph，
 * 匹配在 AclMatcher，缓存与失效在 PermissionCache，读写在 PdoPermissionStore，
 * 文章过滤在 ContentVisibility，回退在 DenyAllAccessControl。核心零权限名。
 */
final class PermissionSystem extends AbstractSystem
{
    private PermissionRegistry $catalog;

    public function name(): string { return 'permission'; }

    public function register(Kernel $kernel): void
    {
        $catalog = new PermissionRegistry($kernel->root());
        $this->catalog = $catalog;

        $kernel->container()->singleton(
            AccessControlInterface::class,
            static function () use ($kernel, $catalog): AccessControlInterface {
                $db = Db::optional($kernel);
                if ($db === null) return new DenyAllAccessControl($catalog);
                $enabled = $kernel->config()->get('permission.cache.enabled', true) === true;
                // 缓存后端可换：file 让基础数据跨请求 / 跨同机进程共享，memory 只在单次请求内生效。
                // 两种后端与「关闭缓存」的决策语义必须完全一致（15c 组守卫）。
                $backend = (string) $kernel->config()->get('permission.cache.driver', 'file') === 'file'
                    ? new FileCacheBackend($kernel->root() . '/storage/cache/permission')
                    : null;
                return new AccessController(
                    new PdoPermissionStore($db), $catalog, $kernel->hooks(), $enabled, $backend
                );
            },
            'system:permission',
            10
        );

        // 文章权限：内容层只询问 content.visible 钩子，不认识权限层的存在
        $kernel->hooks()->filter('content.visible', static function (
            array $posts, array $input, Kernel $k
        ) use ($catalog): array {
            $acl = $k->container()->has(AccessControlInterface::class)
                ? $k->container()->resolve(AccessControlInterface::class)
                : new DenyAllAccessControl($catalog);
            return (new ContentVisibility($acl))->filter($posts, $input['_identity'] ?? null);
        }, 10);

        // 契约覆盖属于「系统权限」。装配期没有请求身份，v0.6 起只留痕 + 允许策略收紧；
        // v0.8 起 require_grant=true 时，还必须先由运行时身份经受保护接口登记授权，
        // 否则一律 fail-closed —— 授权这个动作因此从隐式变成显式、有身份、可审计。
        $kernel->hooks()->filter('contract.override', static function (
            bool $allowed, string $contract, string $from, string $to, int $priority, Kernel $k
        ): bool {
            $require = $k->config()->get('permission.contract_override.require_grant', true) === true;
            $granted = false;
            if ($require) {
                $db = Db::optional($k);
                $granted = $db !== null
                    && (new ContractOverrideLedger($db))->isAuthorized($contract, $to);
            }
            $policy   = (bool) $k->hooks()->apply('permission.contract_override_policy', true, $contract, $to, $priority);
            $decision = $allowed && $policy && (!$require || $granted);
            $k->hooks()->emit('permission.decision', [
                'actor' => 'assembly', 'action' => 'system.contract.override',
                'resource' => $contract, 'decision' => $decision ? 'allow' : 'deny',
                'reason'   => $from . ' -> ' . $to . ' (priority ' . $priority . ')'
                    . ($require ? ($granted ? ' / grant=ok' : ' / grant=missing') : ' / grant=not_required'),
            ]);
            return $decision;
        }, 10);

        // 决策留痕统一进日志系统（核心的可观测性出口）
        $kernel->hooks()->on('permission.decision', static function (array $entry) use ($kernel): void {
            $kernel->trace('权限决策', $entry);
        }, 10);
    }

    public function boot(Kernel $kernel): void
    {
        /** @var AccessControlInterface $acl */
        $acl = $kernel->container()->resolve(AccessControlInterface::class);
        if ($acl instanceof AccessController) {
            $acl->store()->ensureSchema();
            $acl->store()->seed();
            $this->registerAssemblyGrants($kernel);
        }
        if (!$kernel->container()->has(ApiRegistryInterface::class)) return;
        /** @var ApiRegistryInterface $registry */
        $registry = $kernel->container()->resolve(ApiRegistryInterface::class);
        (new ContractOverrideOperations($kernel, $acl))->register($registry);
        (new PermissionOperations($acl, $this->catalog))->register($registry);
    }

    /**
     * 系统层自身的装配期覆盖自动登记 —— 判定规则见 AssemblyGrantRegistrar。
     * 只对已注册系统层的 `system:<name>` 生效；第三方覆盖仍须运行时授权。
     */
    private function registerAssemblyGrants(Kernel $kernel): void
    {
        if ($kernel->config()->get('permission.contract_override.require_grant', true) !== true) return;
        $db = Db::optional($kernel);
        if ($db === null) return;
        (new AssemblyGrantRegistrar(new ContractOverrideLedger($db)))->register(
            $kernel->container()->overrides(),
            array_keys($kernel->systems()->manifests())
        );
    }
}
