<?php
declare(strict_types=1);
namespace Wx\Pages\Account;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Pages\Shared\ApiFailure;

/**
 * 账号自助的写入口 —— 改显示名、改口令；登出由页面交给安全层（见 AccountPage）。
 *
 * 三条纪律：
 *  1. 动作白名单之外一律 422，未知值绝不落成「改显示名」；
 *  2. 身份只来自守卫验签后的票据，页面不兜底成匿名用户；
 *  3. 口令确认在页面层比对，旧口令是否正确交给账号层 —— 不在页面重复判一次。
 */
final class AccountAction
{
    /** @return array{ok: bool, status: int, code: int, message: string} */
    public static function run(Kernel $kernel, Request $request, ?array $identity): array
    {
        $op = trim((string) ($request->body()['op'] ?? ''));
        if (!in_array($op, ['profile', 'password'], true)) {
            return self::fail(422, 42201, '操作只能是 profile / password，当前为「'
                . ($op === '' ? '(空)' : $op) . '」');
        }
        if ($identity === null) {
            return self::fail(401, 40101, '身份未建立：表单票据未通过校验，请刷新页面后重试');
        }

        $container = $kernel->container();
        if (!$container->has(ApiRegistryInterface::class)) {
            return ['ok' => false, 'status' => 503, 'code' => 50301,
                'message' => '接口清单未装配，无法保存。'];
        }
        $registry = $container->resolve(ApiRegistryInterface::class);

        try {
            if ($op === 'profile') {
                $registry->execute('users.me.update', [
                    'display_name' => trim((string) ($request->body()['display_name'] ?? '')),
                    '_identity'    => $identity,
                ]);
                return ['ok' => true, 'status' => 200, 'code' => 0, 'message' => 'ok'];
            }
            return self::password($registry, $request, $identity);
        } catch (\Throwable $e) {
            return ApiFailure::describe($e);
        }
    }

    /** @param array<string, mixed> $identity */
    private static function password(ApiRegistryInterface $registry, Request $request,
        array $identity): array
    {
        $old = (string) ($request->body()['old_password'] ?? '');
        $new = (string) ($request->body()['new_password'] ?? '');
        $again = (string) ($request->body()['confirm_password'] ?? '');
        if ($new !== $again) {
            return self::fail(422, 42201, '两次输入的新口令不一致');
        }

        $changed = $registry->execute('auth.password.change', [
            'old_password' => $old, 'new_password' => $new, '_identity' => $identity,
        ]);
        return ['ok' => true, 'status' => 200, 'code' => 0,
            'message' => '口令已更新，其他设备上的会话已撤销（共 '
                . (int) ($changed['revoked_tokens'] ?? 0) . ' 个）'];
    }

    /** @return array{ok: false, status: int, code: int, message: string} */
    private static function fail(int $status, int $code, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'code' => $code, 'message' => $message];
    }
}
