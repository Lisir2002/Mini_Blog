<?php
declare(strict_types=1);
namespace Wx\Systems\Permission;

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Systems\Api\ApiOperationException;

/**
 * 权限系统的接口执行者登记 —— 从 PermissionSystem 拆出的单一职责协作者。
 *
 * 装配（绑定契约、订阅裁决点、自动登记系统层覆盖）与「把 12 个 operation 绑到执行者」
 * 是两件事：前者决定系统怎么长出来，后者决定对外暴露什么。分开后两边都能独立演进，
 * 单文件也回到职责预算内（规矩 H）。
 */
final class PermissionOperations
{
    public function __construct(
        private AccessControlInterface $acl,
        private PermissionRegistry $catalog
    ) {}

    public function role(): string { return 'permission_operations'; }

    /** 每个 operation 绑定唯一执行者；重复注册由 ApiRegistry 拒绝。 */
    public function register(ApiRegistryInterface $registry): void
    {
        // 静态闭包不能捕获 $this；把两个协作者取成局部变量，闭包只依赖契约对象。
        $acl     = $this->acl;
        $catalog = $this->catalog;

        $registry->registerOperation('permission.catalog', static function (array $in) use ($acl, $catalog): array {
            $all = $acl->catalog();
            return ['permissions' => $all, 'total' => count($all), 'by_system' => $catalog->bySystem()];
        });

        $registry->registerOperation('permission.check', static function (array $in) use ($acl): array {
            $action = trim((string) ($in['action'] ?? ''));
            if ($action === '') throw new ApiOperationException('action 必填', 42201);
            $resource = (array) ($in['resource'] ?? []);
            if (isset($in['resource_type'])) $resource['type'] = (string) $in['resource_type'];
            if (isset($in['resource_id']))   $resource['id']   = (string) $in['resource_id'];
            if (isset($in['field']))         $resource['field'] = (string) $in['field'];
            $identity = $in['_identity'] ?? null;
            return [
                'action'   => $action,
                'decision' => $acl->decide($identity, $action, $resource),
                'allowed'  => $acl->allows($identity, $action, $resource),
            ];
        });

        $registry->registerOperation('permission.roles', static function (array $in) use ($acl): array {
            $roles = $acl instanceof AccessController ? $acl->store()->roles() : [];
            $graph = $acl instanceof AccessController ? $acl->graph() : null;
            foreach ($roles as $name => &$info) {
                $info['parents']               = $graph?->parentsFor($name) ?? [];
                $info['ancestors']             = $graph?->ancestors($name) ?? [];
                $info['effective_permissions'] = $graph?->effectivePermissions($name) ?? $info['permissions'];
            }
            unset($info);
            return ['roles' => $roles, 'max_depth' => $graph ? RoleGraph::MAX_DEPTH : 0];
        });

        $registry->registerOperation('permission.grant', static function (array $in) use ($acl): array {
            return ['changed' => $acl->grant((string) ($in['role'] ?? ''), (string) ($in['permission'] ?? ''))];
        });

        $registry->registerOperation('permission.revoke', static function (array $in) use ($acl): array {
            return ['changed' => $acl->revoke((string) ($in['role'] ?? ''), (string) ($in['permission'] ?? ''))];
        });

        $registry->registerOperation('permission.acl.set', static function (array $in) use ($acl): array {
            return ['changed' => $acl->setAcl([
                'type'       => (string) ($in['type'] ?? 'post'),
                'id'         => (string) ($in['id'] ?? ''),
                'subject'    => (string) ($in['subject'] ?? '*'),
                'capability' => (string) ($in['capability'] ?? '*'),
                'allow'      => (bool) ($in['allow'] ?? true),
                'field'      => (string) ($in['field'] ?? ''),
            ])];
        });

        $registry->registerOperation('permission.acl.list', static function (array $in) use ($acl): array {
            return ['entries' => $acl instanceof AccessController ? $acl->store()->aclList() : []];
        });

        $registry->registerOperation('permission.decisions', static function (array $in) use ($acl): array {
            return [
                'entries' => $acl->decisions(),
                'cache'   => $acl instanceof AccessController ? $acl->cacheStats() : [],
            ];
        });

        $registry->registerOperation('permission.role.tree', static function (array $in) use ($acl): array {
            return $acl instanceof AccessController
                ? $acl->roleTree()
                : ['roles' => [], 'max_depth' => 0, 'warnings' => ['fallback']];
        });

        $registry->registerOperation('permission.role.parent', static function (array $in) use ($acl): array {
            $role   = trim((string) ($in['role'] ?? ''));
            $parent = trim((string) ($in['parent'] ?? ''));
            if ($role === '' || $parent === '') {
                throw new ApiOperationException('role 与 parent 必填', 42201);
            }
            if (!($acl instanceof AccessController)) {
                throw new ApiOperationException('权限层未连库，无法修改继承关系', 50001);
            }
            $result = $acl->setParent($role, $parent, (bool) ($in['remove'] ?? false));
            if (!$result['ok']) {
                throw new ApiOperationException('继承关系被拒绝：' . $result['reason'], 40901);
            }
            return $result;
        });

        // 主体分组：组只是角色来源的扩展，不新增决策层；写操作后由 GroupResolver 显式失效缓存。
        $registry->registerOperation('permission.groups', static function (array $in) use ($acl): array {
            return $acl instanceof AccessController
                ? $acl->groups()->list()
                : ['groups' => [], 'total' => 0, 'warnings' => ['fallback']];
        });

        $registry->registerOperation('permission.group', static function (array $in) use ($acl): array {
            if (!($acl instanceof AccessController)) {
                throw new ApiOperationException('权限层未连库，无法管理分组', 50001);
            }
            $op  = trim((string) ($in['op'] ?? ''));
            $key = trim((string) ($in['group'] ?? ''));
            if ($op === '' || $key === '') {
                throw new ApiOperationException('op 与 group 必填', 42201);
            }
            $groups  = $acl->groups();
            $role    = trim((string) ($in['role'] ?? ''));
            $subject = trim((string) ($in['subject'] ?? ''));
            if (in_array($op, ['add_role', 'remove_role'], true) && $role === '') {
                throw new ApiOperationException('该操作需要 role', 42201);
            }
            if (in_array($op, ['add_member', 'remove_member'], true) && $subject === '') {
                throw new ApiOperationException('该操作需要 subject（如 user:3）', 42201);
            }
            $changed = match ($op) {
                'upsert'        => $groups->upsert(
                    $key,
                    (string) ($in['name'] ?? $key),
                    (string) ($in['description'] ?? ''),
                    (string) ($in['color'] ?? ''),
                    (int) ($in['sort'] ?? 0)
                ),
                'delete'        => $groups->delete($key),
                'add_role'      => $groups->addRole($key, $role),
                'remove_role'   => $groups->removeRole($key, $role),
                'add_member'    => $groups->addMember($key, $subject),
                'remove_member' => $groups->removeMember($key, $subject),
                default         => throw new ApiOperationException('未知 op：' . $op, 42201),
            };
            return ['group' => $key, 'op' => $op, 'changed' => $changed];
        });
    }
}
