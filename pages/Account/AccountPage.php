<?php
declare(strict_types=1);
namespace Wx\Pages\Account;

use Wx\Contracts\PackageInterface;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;
use Wx\Pages\Shared\ConsoleTicket;
use Wx\Systems\Security\PageSession;

/**
 * 账号自助页 —— 登录之后「我是谁、怎么改自己」的落点。
 *
 * 写路径沿用后台的票据机制：GET 时签一张绑定「身份 + /account + 无权限点」的票据，
 * POST 由守卫验签后才进入本页（非 GET 仍不读 Cookie）。本页不碰 Cookie，
 * 只有登出把请求交给安全层去撤销令牌、清凭据。
 */
final class AccountPage implements PackageInterface
{
    private const PATH = '/account';

    public function slug(): string { return 'account'; }

    public function register(Kernel $kernel): void
    {
        $kernel->hooks()->on('route.resolve', function (Request $request, Response $response) use ($kernel): void {
            if ($request->path() !== self::PATH) return;

            if ($request->method() === 'POST') {
                $this->submit($kernel, $request, $response);
                return;
            }

            $identity = ConsoleTicket::identity($kernel, $request);
            if ($identity === null) {
                $response->setStatus(401)->setLayout('deny')->setPayload([
                    'title' => '需要登录', 'page' => 'account', 'status' => 401,
                    'blocks' => [[
                        'type' => 'links', 'title' => '先去登录', 'items' => [
                            ['label' => '登录', 'href' => '/login?next=/account'],
                        ],
                    ]],
                ]);
                return;
            }
            $this->view($kernel, $request, $response, $identity, 200, null, '');
        }, 10);
    }

    private function submit(Kernel $kernel, Request $request, Response $response): void
    {
        $op = (string) ($request->body()['op'] ?? '');
        $container = $kernel->container();
        $session = $container->has(PageSession::class)
            ? $container->resolve(PageSession::class) : null;

        if ($op === 'logout') {
            if (!$session instanceof PageSession) {
                $this->renderUnavailable($response, '数据库未接入，无法撤销会话。');
                return;
            }
            $session->logout($request, $response);
            $response->setStatus(303)->setHeader('Location', '/login?loggedout=1');
            return;
        }

        $identity = ConsoleTicket::identity($kernel, $request);
        // 票据只证明「谁」，不含 token_hash；改密要保留当前会话，必须把 Cookie 的摘要补回来。
        if ($identity !== null && $session instanceof PageSession) {
            $identity = $session->withSessionHash($request, $identity);
        }
        $result = AccountAction::run($kernel, $request, $identity);
        if (($result['ok'] ?? false) === true) {
            $response->setStatus(303)->setHeader('Location', self::PATH . '?saved=' . rawurlencode($op));
            return;
        }

        if ($identity === null) {
            $this->renderUnavailable($response, '身份已失效，请重新登录。');
            return;
        }
        $this->view($kernel, $request, $response, $identity,
            (int) ($result['status'] ?? 422), $result, '');
    }

    /** @param array<string, mixed> $identity @param array<string, mixed>|null $error */
    private function view(Kernel $kernel, Request $request, Response $response, array $identity,
        int $status, ?array $error, string $notice): void
    {
        $data = AccountQuery::fetch($kernel, $identity);
        $ticket = ConsoleTicket::issue($kernel, $request, '', self::PATH);

        $saved = (string) ($request->query()['saved'] ?? '');
        if ($notice === '' && in_array($saved, ['profile', 'password'], true)) {
            $notice = $saved === 'profile' ? '显示名已更新。' : '口令已更新，其他设备已下线。';
        }

        $response->setStatus($status)->setLayout('site')->setPayload([
            'title'  => '我的账号',
            'page'   => 'account',
            'state'  => null,
            'blocks' => AccountBlocks::build(
                (array) $data['user'], (array) $data['session'], $ticket,
                (string) $data['error'], $error, $notice
            ),
            'items'  => [],
        ]);
    }

    private function renderUnavailable(Response $response, string $message): void
    {
        $response->setStatus(503)->setLayout('site')->setPayload([
            'title' => '账号页不可用', 'page' => 'account', 'state' => null, 'items' => [],
            'blocks' => [['type' => 'notice', 'id' => 'account-unavailable', 'tone' => 'warn',
                'state' => 'unavailable', 'title' => '账号页不可用', 'value' => $message]],
        ]);
    }
}
