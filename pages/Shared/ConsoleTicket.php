<?php
declare(strict_types=1);
namespace Wx\Pages\Shared;

use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Systems\Page\FormTicket;
use Wx\Systems\Page\PageAccessGuard;

/**
 * 后台写表单的票据签发 —— 审核队列与内容管理共用同一份实现。
 *
 * 页面守卫对非 GET 不读 Cookie（CSRF 边界），浏览器表单也带不了 Authorization，
 * 所以「提交者是谁」只能由 GET 时签发的短期票据证明。这里集中两件事：
 *  1. 从容器取回守卫在 route.access 阶段已裁决的身份；
 *  2. 用该身份签一张绑定「权限点 + 路径」的票据。
 *
 * 取不到身份或密钥不可写时返回空串：调用方据此**不渲染写入口**，
 * 既不签无签名票据，也不渲染一个点了必然 401 的按钮。
 */
final class ConsoleTicket
{
    /** @return array<string, mixed>|null */
    public static function identity(Kernel $kernel, Request $request): ?array
    {
        $container = $kernel->container();
        if (!$container->has(PageAccessGuard::class)) return null;
        $guard = $container->resolve(PageAccessGuard::class);
        if (!$guard instanceof PageAccessGuard) return null;
        return $guard->identityFor($request);
    }

    public static function issue(Kernel $kernel, Request $request, string $permission, string $path): string
    {
        $identity = self::identity($kernel, $request);
        if ($identity === null) return '';

        $ticket = (new FormTicket($kernel->root() . '/storage'))->sign(
            (int) ($identity['user_id'] ?? 0), (string) ($identity['username'] ?? ''),
            (string) ($identity['role'] ?? 'user'), $permission, $path
        );
        return is_string($ticket) ? $ticket : '';
    }
}
