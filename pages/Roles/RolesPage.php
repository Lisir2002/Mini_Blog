<?php
declare(strict_types=1);
namespace Wx\Pages\Roles;

use Wx\Contracts\PackageInterface;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;
use Wx\Pages\Shared\ConsoleTicket;

/**
 * 角色授权页 —— 把「改授权只能靠 curl」变成可在站点上完成的事。
 *
 * 页面只声明 permission.read：看角色与许可是读权限；写操作由 RolesAction 再问一次
 * permission.manage。没有 manage 时渲染只读表格，不渲染必然 403 的按钮。
 *
 * 撤销后必须复核「有效许可里还有没有它」：继承来的许可撤不掉，
 * 只回一句「已撤销」会让管理员以为收回了权限。这句复核放在这里，
 * 因为只有页面同时握着新取到的有效许可集合。
 */
final class RolesPage implements PackageInterface
{
    private const PATH = '/console/roles';
    private const PERMISSION = 'permission.read';

    public function slug(): string { return 'roles'; }

    public function register(Kernel $kernel): void
    {
        $kernel->hooks()->on('route.resolve', function (Request $request, Response $response) use ($kernel): void {
            if ($request->path() !== self::PATH) return;
            $identity = ConsoleTicket::identity($kernel, $request);

            if ($request->method() === 'POST') {
                $this->submit($kernel, $request, $response, $identity);
                return;
            }
            $this->view($kernel, $request, $response, $identity, 200, null);
        }, 10);
    }

    private function submit(Kernel $kernel, Request $request, Response $response, ?array $identity): void
    {
        $result = RolesAction::run($kernel, $request, $identity);
        if (($result['ok'] ?? false) === true) {
            $query = [
                'saved' => (string) ($result['action'] ?? ''),
                'role'  => (string) ($result['role'] ?? ''),
                'changed' => ($result['changed'] ?? true) ? '1' : '0',
            ];
            foreach (['permission', 'parent'] as $key) {
                $value = (string) ($result[$key] ?? '');
                if ($value !== '') $query[$key] = $value;
            }
            $response->setStatus(303)->setHeader('Location', self::PATH . '?' . http_build_query($query));
            return;
        }
        $this->view($kernel, $request, $response, $identity,
            (int) ($result['status'] ?? 422), $result);
    }

    /** @param array<string, mixed>|null $identity @param array<string, mixed>|null $error */
    private function view(Kernel $kernel, Request $request, Response $response, ?array $identity,
        int $status, ?array $error): void
    {
        $query = $request->query();
        $showCatalog = (string) ($query['catalog'] ?? '') === '1';
        $data = RolesQuery::fetch($kernel);
        $canManage = RolesQuery::canManage($kernel, $identity);
        $ticket = $canManage ? ConsoleTicket::issue($kernel, $request, self::PERMISSION, self::PATH) : '';

        $response->setStatus($status)->setLayout('admin')->setPayload([
            'title'  => '角色授权',
            'page'   => 'roles',
            'view'   => 'roles',
            'state'  => null,
            'blocks' => RolesBlocks::build(
                $data, $ticket, $canManage, $showCatalog, self::savedNotice($query, $data), $error
            ),
        ]);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $data 已按当前状态重新取过，用来复核继承覆盖
     */
    private static function savedNotice(array $query, array $data): string
    {
        $action = (string) ($query['saved'] ?? '');
        if ($action === '') return '';
        $role = (string) ($query['role'] ?? '');
        $perm = (string) ($query['permission'] ?? '');
        $parent = (string) ($query['parent'] ?? '');
        $changed = (string) ($query['changed'] ?? '1') === '1';

        if (!$changed) return self::noChangeNotice($action, $role, $perm, $parent, $data);
        return match ($action) {
            'grant'  => '已给 ' . $role . ' 授予直接许可 ' . $perm . '。',
            'revoke' => self::revokeNotice($role, $perm, $data),
            'set_parent'    => $role . ' 现在继承 ' . $parent . '。',
            'remove_parent' => '已解除 ' . $role . ' 对 ' . $parent . ' 的继承。',
            default => '',
        };
    }

    /**
     * 「什么都没改」也要说清楚是哪一种没改：本来就有、本来就没有、来自继承。
     * 一句「没有变化」会让管理员分不清是重复提交还是被继承挡住了。
     *
     * @param array<string, mixed> $data
     */
    private static function noChangeNotice(string $action, string $role, string $perm,
        string $parent, array $data): string
    {
        if ($action === 'revoke') {
            $info = (array) ($data['roles'][$role] ?? []);
            $effective = array_map('strval', (array) ($info['effective_permissions'] ?? []));
            if (in_array($perm, $effective, true) || in_array('*', $effective, true)) {
                return $role . ' 本来就没有直接许可 ' . $perm
                    . ' —— 它来自继承或通配符。撤销只删直接许可，要收回得改父角色。';
            }
            return $role . ' 本来就没有 ' . $perm . '，没有变化。';
        }
        return match ($action) {
            'grant'  => $role . ' 本来就有直接许可 ' . $perm . '，没有变化。',
            'set_parent'    => $role . ' 本来就继承 ' . $parent . '，没有变化。',
            'remove_parent' => $role . ' 本来就没有继承 ' . $parent . '，没有变化。',
            default => '没有变化：这条记录本来就是这个状态。',
        };
    }

    /** @param array<string, mixed> $data */
    private static function revokeNotice(string $role, string $perm, array $data): string
    {
        $info = (array) ($data['roles'][$role] ?? []);
        $effective = array_map('strval', (array) ($info['effective_permissions'] ?? []));
        if (in_array($perm, $effective, true) || in_array('*', $effective, true)) {
            return '已删除 ' . $role . ' 的直接许可 ' . $perm
                . '，但它仍通过继承或通配符生效 —— 要真正收回得改父角色。';
        }
        return '已撤销 ' . $role . ' 的许可 ' . $perm . '，有效许可里已经没有它。';
    }
}
