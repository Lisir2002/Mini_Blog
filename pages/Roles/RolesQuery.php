<?php
declare(strict_types=1);
namespace Wx\Pages\Roles;

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Kernel;

/**
 * 角色与许可的取数 —— 只读 permission.roles 与 permission.catalog。
 *
 * 两张表必须一起读：授权表单要判断「这个权限点是否真的存在」，
 * 只看角色列表会把清单外的字符串当成合法的可授权目标。
 * 是否渲染写表单由 canManage 决定，判断用的是与接口同一个 ACL，
 * 不另建一套「页面上的管理员」概念。
 */
final class RolesQuery
{
    /**
     * @return array{roles: array<string, array<string, mixed>>, max_depth: int,
     *               catalog: array<string, array<string, string>>, total: int, error: string}
     */
    public static function fetch(Kernel $kernel): array
    {
        $container = $kernel->container();
        if (!$container->has(ApiRegistryInterface::class)) {
            return self::empty('接口清单未装配，无法读取角色。');
        }
        $registry = $container->resolve(ApiRegistryInterface::class);
        try {
            $roles   = $registry->execute('permission.roles', []);
            $catalog = $registry->execute('permission.catalog', []);
        } catch (\Throwable $e) {
            return self::empty('角色与许可读取失败：' . $e->getMessage());
        }

        $out = [];
        foreach ((array) ($roles['roles'] ?? []) as $name => $info) {
            if (is_array($info)) $out[(string) $name] = $info;
        }
        $perms = [];
        foreach ((array) ($catalog['permissions'] ?? []) as $name => $info) {
            if (is_array($info)) $perms[(string) $name] = $info;
        }
        return [
            'roles' => $out, 'max_depth' => (int) ($roles['max_depth'] ?? 0),
            'catalog' => $perms, 'total' => count($out), 'error' => '',
        ];
    }

    /** @param array<string, mixed>|null $identity */
    public static function canManage(Kernel $kernel, ?array $identity): bool
    {
        $c = $kernel->container();
        $acl = $c->has(AccessControlInterface::class) ? $c->resolve(AccessControlInterface::class) : null;
        return $acl !== null && $identity !== null && $acl->allows($identity, 'permission.manage');
    }

    /**
     * @return array{roles: array<string, array<string, mixed>>, max_depth: int,
     *               catalog: array<string, array<string, string>>, total: int, error: string}
     */
    private static function empty(string $error): array
    {
        return ['roles' => [], 'max_depth' => 0, 'catalog' => [], 'total' => 0, 'error' => $error];
    }
}
