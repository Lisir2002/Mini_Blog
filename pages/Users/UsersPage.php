<?php
declare(strict_types=1);
namespace Wx\Pages\Users;

use Wx\Contracts\PackageInterface;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;
use Wx\Pages\Shared\ConsoleTicket;

/**
 * 用户管理页 —— 把「授权只能靠 curl」变成可在站点上完成的事。
 *
 * 页面只声明 user.read：看列表是读权限；写操作由 UsersAction 再问一次 user.manage。
 * 没有 manage 时渲染只读表格，不渲染点了必然 403 的按钮。
 *
 * 失败一律留在原页：不能禁用自己 / 不能移除最后一个管理员是账号层的 40906，
 * 页面原样呈现，不自己再判一遍。
 */
final class UsersPage implements PackageInterface
{
    private const PATH = '/console/users';
    private const PERMISSION = 'user.read';

    public function slug(): string { return 'users'; }

    public function register(Kernel $kernel): void
    {
        $kernel->hooks()->on('route.resolve', function (Request $request, Response $response) use ($kernel): void {
            if ($request->path() !== self::PATH) return;
            $identity = ConsoleTicket::identity($kernel, $request);

            if ($request->method() === 'POST') {
                $this->submit($kernel, $request, $response, $identity);
                return;
            }
            $this->view($kernel, $request, $response, $identity, 200, null, '');
        }, 10);
    }

    private function submit(Kernel $kernel, Request $request, Response $response, ?array $identity): void
    {
        $result = UsersAction::run($kernel, $request, $identity);
        if (($result['ok'] ?? false) === true) {
            $action = (string) ($request->body()['action'] ?? '');
            $response->setStatus(303)->setHeader('Location',
                self::PATH . '?saved=' . rawurlencode($action));
            return;
        }
        $this->view($kernel, $request, $response, $identity,
            (int) ($result['status'] ?? 422), $result, '');
    }

    /** @param array<string, mixed>|null $identity @param array<string, mixed>|null $error */
    private function view(Kernel $kernel, Request $request, Response $response, ?array $identity,
        int $status, ?array $error, string $notice): void
    {
        $statusFilter = (string) ($request->query()['status'] ?? '');
        if (!in_array($statusFilter, ['', 'active', 'disabled'], true)) $statusFilter = '';
        $offset = max(0, (int) ($request->query()['offset'] ?? 0));

        $data = UsersQuery::fetch($kernel, $statusFilter, $offset);
        $canManage = UsersQuery::canManage($kernel, $identity);
        $ticket = $canManage ? ConsoleTicket::issue($kernel, $request, self::PERMISSION, self::PATH) : '';

        if ($notice === '') $notice = self::savedNotice((string) ($request->query()['saved'] ?? ''));

        $response->setStatus($status)->setLayout('admin')->setPayload([
            'title'  => '用户管理',
            'page'   => 'users',
            'view'   => 'users',
            'state'  => null,
            'blocks' => UsersBlocks::build(
                $data, $ticket, $canManage, $statusFilter, $offset, $notice, $error
            ),
        ]);
    }

    private static function savedNotice(string $saved): string
    {
        return match ($saved) {
            'disable' => '账号已禁用，该账号的全部令牌已撤销。',
            'enable' => '账号已启用。',
            'set_role' => '角色已更新。',
            'reset_password' => '口令已重置，该账号的全部令牌已撤销。',
            default => '',
        };
    }
}
