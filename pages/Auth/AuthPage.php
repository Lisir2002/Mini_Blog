<?php
declare(strict_types=1);
namespace Wx\Pages\Auth;

use Wx\Contracts\PackageInterface;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;
use Wx\Pages\Shared\ConsoleTicket;
use Wx\Systems\Security\PageSession;

/**
 * 登录 / 注册入口 —— 浏览器里唯一能建立页面会话的地方。
 *
 * 此前 auth.session.* 与 auth.register 全部零消费：后台 401 提示「需要登录」，
 * 但站点上没有任何一个页面能登录，只能手工构造 POST。本页补的就是这道门。
 *
 * 布局用 site：登录是公开面，不预设主题提供 admin 槽位；页面只产区块数据。
 * 注册关闭时不渲染表单（渲染一个必然 403 的按钮是假入口），但仍保留登录链接。
 */
final class AuthPage implements PackageInterface
{
    private const LOGIN = '/login';
    private const REGISTER = '/register';

    public function slug(): string { return 'auth'; }

    public function register(Kernel $kernel): void
    {
        $kernel->hooks()->on('route.resolve', function (Request $request, Response $response) use ($kernel): void {
            $path = $request->path();
            if ($path !== self::LOGIN && $path !== self::REGISTER) return;

            $container = $kernel->container();
            $session = $container->has(PageSession::class)
                ? $container->resolve(PageSession::class) : null;
            if (!$session instanceof PageSession) {
                // 没有库就没有会话：直接说「不可用」，不渲染一个提交后必 501 的表单。
                $this->render($response, $path, 503, [[
                    'type' => 'notice', 'id' => 'auth-unavailable', 'tone' => 'warn',
                    'state' => 'unavailable', 'title' => '登录不可用',
                    'value' => '数据库未接入，当前无法登录或注册。',
                ]]);
                return;
            }

            if ($request->method() === 'POST') {
                $this->submit($kernel, $session, $request, $response, $path);
                return;
            }

            if ($path === self::LOGIN && ConsoleTicket::identity($kernel, $request) !== null) {
                $response->setStatus(303)->setHeader('Location', self::target($request));
                return;
            }

            $query = $request->query();
            $notice = (string) ($query['registered'] ?? '') === '1'
                ? '账号已创建，请用刚才的用户名与口令登录。'
                : ((string) ($query['loggedout'] ?? '') === '1' ? '已退出当前会话。' : '');
            $closed = $path === self::REGISTER
                && $kernel->config()->get('user.registration.enabled', false) !== true;
            $blocks = $path === self::LOGIN
                ? AuthBlocks::login([], null, $notice, self::target($request))
                : AuthBlocks::register([], null, $closed);
            $this->render($response, $path, 200, $blocks);
        }, 10);
    }

    private function submit(Kernel $kernel, PageSession $session, Request $request,
        Response $response, string $path): void
    {
        $values = [
            'username'     => trim((string) ($request->body()['username'] ?? '')),
            'display_name' => trim((string) ($request->body()['display_name'] ?? '')),
        ];

        if ($path === self::LOGIN) {
            $result = AuthAction::login($session, $request);
            if (($result['ok'] ?? false) === true) {
                // 换号登录：先撤销旧 Cookie 指向的残留凭据，再签发新会话。
                // 失败不阻断登录（新 Cookie 会覆盖旧值），静默处理。
                $session->revokeResidual($request);
                $session->apply($response, (array) ($result['session'] ?? []));
                $response->setStatus(303)->setHeader('Location', self::target($request));
                return;
            }
            $this->render($response, $path, (int) $result['status'],
                AuthBlocks::login($values, $result, '', self::target($request)));
            return;
        }

        $result = AuthAction::register($kernel, $request);
        if (($result['ok'] ?? false) !== true) {
            $this->render($response, $path, (int) $result['status'],
                AuthBlocks::register($values, $result, false));
            return;
        }

        // 账号已建：紧接着用同一组凭据建立浏览器会话。失败不能让用户以为注册失败。
        $login = AuthAction::login($session, $request);
        if (($login['ok'] ?? false) === true) {
            $session->revokeResidual($request);
            $session->apply($response, (array) ($login['session'] ?? []));
            $response->setStatus(303)->setHeader('Location', '/account');
            return;
        }
        $response->setStatus(303)->setHeader('Location', self::LOGIN . '?registered=1');
    }

    /**
     * 只接受站内绝对路径：`//evil.example` 与完整 URL 一律回落到 /account，
     * 既不开放重定向，也不把「没给 next」当成错误。
     */
    private static function target(Request $request): string
    {
        $raw = $request->method() === 'POST'
            ? (string) ($request->body()['next'] ?? '')
            : (string) ($request->query()['next'] ?? '');
        $next = trim($raw);
        if ($next === '' || $next[0] !== '/' || str_starts_with($next, '//')) return '/account';
        if (in_array($next, [self::LOGIN, self::REGISTER], true)) return '/account';
        return $next;
    }

    /** @param list<array<string, mixed>> $blocks */
    private function render(Response $response, string $path, int $status, array $blocks): void
    {
        $isLogin = $path === self::LOGIN;
        $response->setStatus($status)->setLayout('site')->setPayload([
            'title'  => $isLogin ? '登录' : '注册',
            'page'   => $isLogin ? 'login' : 'register',
            'state'  => null,
            'blocks' => $blocks,
            'items'  => [],
        ]);
    }
}
