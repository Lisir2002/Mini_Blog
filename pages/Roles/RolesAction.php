<?php
declare(strict_types=1);
namespace Wx\Pages\Roles;

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Pages\Shared\ApiFailure;

/**
 * 角色授权的写入口 —— 授予 / 撤销许可、改继承关系。
 *
 * 页面声明的是 permission.read（能看），所以写操作在这里再问一次 permission.manage：
 * 页面能直接调执行者，绕过了 API 分发器的权限检查。权限层缺席时 fail-closed。
 *
 * 两类「接口不管、页面必须管」的脏数据在这里被挡住：
 *   1. 授权到清单里没有的权限点 —— store 直接 INSERT，不会报错，
 *      结果是页面上写着「已授予」，决策时永远不命中；
 *   2. 授权给不存在的角色 —— 许可行写进库了，角色列表却看不到它，
 *      变成谁也解释不清的孤儿授权。
 *
 * 高危操作（改 admin 角色、动通配符 `*`）要求手写角色名确认：
 * 撤销 admin 的 permission.manage 之后，这个页面自己也进不去了。
 */
final class RolesAction
{
    public const ACTIONS = ['grant', 'revoke', 'set_parent', 'remove_parent'];

    /** @return array{ok: bool, status: int, code: int, message: string, changed?: bool,
     *                action?: string, role?: string, permission?: string, parent?: string} */
    public static function run(Kernel $kernel, Request $request, ?array $identity): array
    {
        $body       = $request->body();
        $action     = trim((string) ($body['action'] ?? ''));
        $role       = trim((string) ($body['role'] ?? ''));
        $permission = trim((string) ($body['permission'] ?? ''));
        $parent     = trim((string) ($body['parent'] ?? ''));
        $confirm    = trim((string) ($body['confirm_role'] ?? ''));

        if (!in_array($action, self::ACTIONS, true)) {
            return self::fail(422, 42201, '动作只能是 ' . implode(' / ', self::ACTIONS)
                . '，当前为「' . ($action === '' ? '(空)' : $action) . '」');
        }
        if ($role === '') return self::fail(422, 42201, '请填写角色名');
        if ($identity === null) return self::fail(401, 40101, '身份未建立，请刷新页面后重试');

        $container = $kernel->container();
        $acl = $container->has(AccessControlInterface::class)
            ? $container->resolve(AccessControlInterface::class) : null;
        if ($acl === null || !$acl->allows($identity, 'permission.manage')) {
            return self::fail(403, 40303, '缺少 permission.manage 权限点，写操作被拒绝（fail-closed）');
        }
        if (!$container->has(ApiRegistryInterface::class)) {
            return self::fail(503, 50301, '接口清单未装配，无法修改授权。');
        }
        $registry = $container->resolve(ApiRegistryInterface::class);

        try {
            $roles = (array) ($registry->execute('permission.roles', [])['roles'] ?? []);
            $perms = (array) ($registry->execute('permission.catalog', [])['permissions'] ?? []);
        } catch (\Throwable $e) {
            return ApiFailure::describe($e);
        }
        if (!isset($roles[$role])) {
            return self::fail(422, 42201, '角色「' . $role . '」不存在。角色只能由系统层播种，'
                . '本页不新建角色 —— 授权给不存在的角色会写出看不见的孤儿许可。');
        }

        $isGrant = in_array($action, ['grant', 'revoke'], true);
        if ($isGrant) {
            if ($permission === '') return self::fail(422, 42201, '请填写权限点');
            if ($permission !== '*' && !isset($perms[$permission])) {
                return self::fail(422, 42201, '权限点「' . $permission . '」不在清单里。'
                    . '只有各系统层 self report 的名字才生效，写错的授权不会报错，但永远不命中。');
            }
            if (self::isRisky($role, $permission) && $confirm !== $role) {
                return self::fail(422, 42201, '这是高危操作：请在「确认角色名」里手写 ' . $role
                    . ' 再提交。撤销管理员的 manage 或通配符会让这个页面自己也进不去。');
            }
        } else {
            if ($parent === '') return self::fail(422, 42201, '请填写父角色');
            if (!isset($roles[$parent])) {
                return self::fail(422, 42201, '父角色「' . $parent . '」不存在');
            }
        }

        $in = ['role' => $role, '_identity' => $identity];
        try {
            $out = match ($action) {
                'grant'      => $registry->execute('permission.grant',
                    $in + ['permission' => $permission]),
                'revoke'     => $registry->execute('permission.revoke',
                    $in + ['permission' => $permission]),
                'set_parent' => $registry->execute('permission.role.parent',
                    $in + ['parent' => $parent]),
                'remove_parent' => $registry->execute('permission.role.parent',
                    $in + ['parent' => $parent, 'remove' => true]),
            };
        } catch (\Throwable $e) {
            return self::translate(ApiFailure::describe($e), $action);
        }

        return [
            'ok' => true, 'status' => 200, 'code' => 0, 'message' => 'ok',
            'changed' => (bool) ($out['changed'] ?? $out['ok'] ?? true),
            'action' => $action, 'role' => $role, 'permission' => $permission, 'parent' => $parent,
        ];
    }

    /** 改 admin 或动通配符都会改变「谁还能回来改授权」，一律要求手写确认。 */
    private static function isRisky(string $role, string $permission): bool
    {
        return $role === 'admin' || $permission === '*';
    }

    /**
     * 继承被拒的 reason 是英文枚举（self / cycle / depth），直接显示等于没解释。
     * 只翻译文案，不改系统层的错误码与判定。
     *
     * @param array{ok: false, status: int, code: int, message: string} $failure
     * @return array{ok: false, status: int, code: int, message: string}
     */
    private static function translate(array $failure, string $action): array
    {
        if (($failure['code'] ?? 0) !== 40901) return $failure;
        $hints = [
            'self'  => '角色不能继承自己。',
            'cycle' => '会形成环：目标父角色已经在这个角色的下游，继承之后两边互相继承。',
            'depth' => '继承深度超过上限，链太长会让有效许可集难以解释。',
        ];
        foreach ($hints as $reason => $text) {
            if (!str_contains((string) $failure['message'], $reason)) continue;
            $failure['message'] = $text . '（系统层判定：' . $reason . '）';
            break;
        }
        return $failure;
    }

    /** @return array{ok: false, status: int, code: int, message: string} */
    private static function fail(int $status, int $code, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'code' => $code, 'message' => $message];
    }
}
