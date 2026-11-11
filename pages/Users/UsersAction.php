<?php
declare(strict_types=1);
namespace Wx\Pages\Users;

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Pages\Shared\ApiFailure;

/**
 * 用户管理的写入口 —— 禁用 / 启用 / 改角色 / 重置口令。
 *
 * 页面声明的是 user.read（能看列表），所以写操作必须在这里再问一次 user.manage：
 * 页面能直接调执行者，绕过了 API 分发器的权限检查；不自己补这一道，
 * 「能看列表」就会悄悄等于「能禁用任何人」。权限层缺席时 fail-closed。
 *
 * 「不能禁用自己 / 不能移除最后一个管理员」由账号层抛 40906，页面只负责原样呈现，
 * 不再抄一遍判断 —— 抄一遍就会有两套规则，迟早分叉。
 */
final class UsersAction
{
    public const ACTIONS = ['disable', 'enable', 'set_role', 'reset_password'];

    /** @return array{ok: bool, status: int, code: int, message: string} */
    public static function run(Kernel $kernel, Request $request, ?array $identity): array
    {
        $body   = $request->body();
        $action = trim((string) ($body['action'] ?? ''));
        $userId = (int) ($body['user_id'] ?? 0);

        if ($userId <= 0) return self::fail(422, 42201, 'user_id 缺失或不是正整数');
        if (!in_array($action, self::ACTIONS, true)) {
            return self::fail(422, 42201, '动作只能是 ' . implode(' / ', self::ACTIONS)
                . '，当前为「' . ($action === '' ? '(空)' : $action) . '」');
        }
        if ($identity === null) return self::fail(401, 40101, '身份未建立，请刷新页面后重试');

        $container = $kernel->container();
        $acl = $container->has(AccessControlInterface::class)
            ? $container->resolve(AccessControlInterface::class) : null;
        if ($acl === null || !$acl->allows($identity, 'user.manage')) {
            return self::fail(403, 40303, '缺少 user.manage 权限点，写操作被拒绝（fail-closed）');
        }
        if (!$container->has(ApiRegistryInterface::class)) {
            return self::fail(503, 50301, '接口清单未装配，无法执行账号操作。');
        }

        $in = ['user_id' => $userId, 'action' => $action, '_identity' => $identity];
        if ($action === 'set_role') $in['role'] = trim((string) ($body['role'] ?? ''));
        if ($action === 'reset_password') $in['new_password'] = (string) ($body['new_password'] ?? '');

        try {
            $container->resolve(ApiRegistryInterface::class)->execute('users.manage', $in);
        } catch (\Throwable $e) {
            return ApiFailure::describe($e);
        }
        return ['ok' => true, 'status' => 200, 'code' => 0, 'message' => 'ok'];
    }

    /** @return array{ok: false, status: int, code: int, message: string} */
    private static function fail(int $status, int $code, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'code' => $code, 'message' => $message];
    }
}
