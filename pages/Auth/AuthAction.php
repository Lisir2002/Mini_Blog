<?php
declare(strict_types=1);
namespace Wx\Pages\Auth;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Pages\Shared\ApiFailure;
use Wx\Systems\Security\PageSession;

/**
 * 登录与注册的写入口 —— 只做字段校验与调用，不判断口令是否正确。
 *
 * 口令长度、弱口令、锁定、禁用全部交给账号层：页面如果自己先判一遍，
 * 就会出现「页面说口令太短、接口说账号已锁定」的双口径。
 * 这里唯一的本地规则是「必填」——空字段不值得浪费一次限流配额。
 */
final class AuthAction
{
    /**
     * @return array{ok: bool, status: int, code: int, message: string, session?: array<string, mixed>}
     */
    public static function login(PageSession $session, Request $request): array
    {
        $body = $request->body();
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        if ($username === '') return self::fail('请输入用户名');
        if ($password === '') return self::fail('请输入口令');

        try {
            $issued = $session->login($username, $password, $request->clientIp());
        } catch (\Throwable $e) {
            return ApiFailure::describe($e);
        }
        return ['ok' => true, 'status' => 200, 'code' => 0, 'message' => 'ok', 'session' => $issued];
    }

    /**
     * 注册成功后不直接回 303：页面还要用同一组凭据建立浏览器会话。
     * 返回 created=true 让调用方区分「账号已建、自动登录失败」这两种结果。
     * @return array{ok: bool, status: int, code: int, message: string, user?: array<string, mixed>}
     */
    public static function register(Kernel $kernel, Request $request): array
    {
        $body = $request->body();
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        if ($username === '') return self::fail('请输入用户名');
        if ($password === '') return self::fail('请输入口令');

        $container = $kernel->container();
        if (!$container->has(ApiRegistryInterface::class)) {
            return ['ok' => false, 'status' => 503, 'code' => 50301,
                'message' => '接口清单未装配，无法注册。'];
        }

        try {
            $created = $container->resolve(ApiRegistryInterface::class)->execute('auth.register', [
                'username' => $username, 'password' => $password,
                'display_name' => trim((string) ($body['display_name'] ?? '')),
                '_ip' => $request->clientIp(),
            ]);
        } catch (\Throwable $e) {
            return ApiFailure::describe($e);
        }
        return ['ok' => true, 'status' => 200, 'code' => 0, 'message' => 'ok',
            'user' => (array) ($created['user'] ?? [])];
    }

    /** @return array{ok: false, status: int, code: int, message: string} */
    private static function fail(string $message): array
    {
        return ['ok' => false, 'status' => 422, 'code' => 42201, 'message' => $message];
    }
}
