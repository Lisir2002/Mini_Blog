<?php
declare(strict_types=1);
namespace Wx\Pages\Acl;

use Wx\Contracts\PackageInterface;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;
use Wx\Pages\Shared\ConsoleTicket;

/**
 * 资源 ACL 与用户组页（v0.44）。
 *
 * 成功 303 回到原面板并保留刚选中的文章 / 用户，失败绝不跳转并回填。
 * 页面声明 permission.read；写动作在 AclAction 里现场问 permission.manage，
 * 票据只证明「谁从哪一页交的」，不替代裁决。
 */
final class AclPage implements PackageInterface
{
    private const PATH = '/console/acl';
    private const PERMISSION = 'permission.read';

    public function slug(): string { return 'acl'; }

    public function register(Kernel $kernel): void
    {
        $kernel->hooks()->on('route.resolve', function (Request $request, Response $response) use ($kernel): void {
            if ($request->path() !== self::PATH) return;
            $identity = ConsoleTicket::identity($kernel, $request);
            if ($request->method() === 'POST') {
                $this->submit($kernel, $request, $response, $identity);
                return;
            }
            if ($request->method() !== 'GET' && $request->method() !== 'HEAD') {
                $response->setStatus(405)->setHeader('Allow', 'GET, HEAD, POST')->setPayload([
                    'title' => '方法不支持', 'page' => 'acl', 'view' => 'acl',
                    'state' => 'deny', 'blocks' => [[
                        'type' => 'notice', 'id' => 'acl-method', 'tone' => 'warn',
                        'state' => 'denied', 'title' => '这个页面只接受 GET 与 POST',
                        'value' => '选文章 / 切面板 / 查账号走查询参数，改动走带票据的表单提交。',
                    ]],
                ]);
                return;
            }
            $this->view($kernel, $request, $response, $identity, 200, null, []);
        }, 10);
    }

    private function submit(Kernel $kernel, Request $request, Response $response, ?array $identity): void
    {
        $result = AclAction::run($kernel, $request, $identity);
        if (($result['ok'] ?? false) === true) {
            $query = [
                'panel'   => (string) ($result['panel'] ?? 'acl'),
                'saved'   => (string) ($result['panel'] ?? 'acl'),
                'outcome' => (string) ($result['outcome'] ?? ''),
                'changed' => ($result['changed'] ?? false) ? '1' : '0',
                'detail'  => (string) ($result['detail'] ?? ''),
                'hint'    => (string) ($result['hint'] ?? ''),
            ];
            foreach (['slug', 'group', 'user'] as $keep) {
                $value = (string) ($result[$keep] ?? $request->query()[$keep] ?? '');
                if ($value !== '') $query[$keep] = $value;
            }
            $response->setStatus(303)->setHeader('Location', self::PATH . '?' . http_build_query($query));
            return;
        }
        $this->view($kernel, $request, $response, $identity,
            (int) ($result['status'] ?? 422), $result, $request->body());
    }

    /**
     * @param array<string, mixed>|null $identity
     * @param array<string, mixed>|null $error
     * @param array<string, mixed> $form
     */
    private function view(Kernel $kernel, Request $request, Response $response, ?array $identity,
        int $status, ?array $error, array $form): void
    {
        $query = $request->query();
        if ($error !== null) {
            // 表单的 action 已带 panel / slug，这里只在直接构造 POST 时补回上下文。
            foreach (['panel', 'slug', 'group', 'user'] as $key) {
                if ((string) ($query[$key] ?? '') === '' && (string) ($form[$key] ?? '') !== '') {
                    $query[$key] = (string) $form[$key];
                }
            }
        }
        $data = AclQuery::fetch($kernel, $identity, $query);
        $data['ticket'] = ConsoleTicket::issue($kernel, $request, self::PERMISSION, self::PATH);
        $data['form'] = $form;
        $data['saved'] = $query;
        if ($error !== null) $data['error'] = $error;

        $httpStatus = $error !== null ? $status : (int) ($data['status'] ?? $status);
        $response->setStatus($httpStatus)->setLayout('admin')->setPayload([
            'title' => '资源 ACL 与用户组', 'page' => 'acl', 'view' => 'acl',
            'state' => ($data['ok'] ?? false) === true ? null : 'error',
            'blocks' => AclBlocks::build($data),
        ]);
    }
}
