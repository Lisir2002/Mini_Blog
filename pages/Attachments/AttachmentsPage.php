<?php
declare(strict_types=1);
namespace Wx\Pages\Attachments;

use Wx\Contracts\PackageInterface;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;
use Wx\Pages\Shared\ConsoleTicket;

/**
 * 附件库页 —— 选文章、看附件、选一条来改（v0.43）。
 *
 * 成功一律 303 回到当前文章（改挂载时回到新文章的清单），失败绝不跳转并回填。
 * 页面声明 attachment.manage；写操作在 AttachmentsAction 里再问一次，
 * 票据只证明「谁从哪一页交的」，不替代裁决。
 */
final class AttachmentsPage implements PackageInterface
{
    private const PATH = '/console/attachments';
    private const PERMISSION = 'attachment.read';

    public function slug(): string { return 'attachments'; }

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
                    'title' => '方法不支持', 'page' => 'attachments', 'view' => 'attachments',
                    'state' => 'deny', 'blocks' => [[
                        'type' => 'notice', 'id' => 'attach-method', 'tone' => 'warn',
                        'state' => 'denied', 'title' => '这个页面只接受 GET 与 POST',
                        'value' => '选文章 / 选附件走查询参数，改动走带票据的表单提交。',
                    ]],
                ]);
                return;
            }
            $this->view($kernel, $request, $response, $identity, 200, null, []);
        }, 10);
    }

    private function submit(Kernel $kernel, Request $request, Response $response, ?array $identity): void
    {
        $result = AttachmentsAction::run($kernel, $request, $identity);
        if (($result['ok'] ?? false) === true) {
            $op = (string) ($result['op'] ?? '');
            // 改挂载后这条附件已不属于当前文章的清单，别把 id 带回去渲染「找不到」。
            $keepId = $op === 'move' ? '' : (string) ($result['id'] ?? '');
            $query = [
                'saved' => $op, 'outcome' => (string) ($result['outcome'] ?? ''),
                'changed' => ($result['changed'] ?? false) ? '1' : '0',
                'detail' => (string) ($result['detail'] ?? ''),
                'hint' => (string) ($result['hint'] ?? ''),
                'slug' => (string) ($request->body()['slug'] ?? ''),
            ];
            if ($keepId !== '') $query['id'] = $keepId;
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
        $data = AttachmentsQuery::fetch($kernel, $identity, $query);
        // 写失败时按表单里的 slug / id 回到同一条附件，而不是跳回文章清单。
        if ($error !== null) {
            $data['slug'] = (string) ($form['slug'] ?? $data['slug']);
            $data['id'] = (int) ($form['id'] ?? $data['id']);
        }
        $data['ticket'] = ConsoleTicket::issue($kernel, $request, self::PERMISSION, self::PATH);
        $data['form'] = $form;
        $data['saved'] = $query;
        if ($error !== null) $data['error'] = $error;

        $httpStatus = $error !== null ? $status : (int) ($data['status'] ?? $status);
        $response->setStatus($httpStatus)->setLayout('admin')->setPayload([
            'title' => '附件库', 'page' => 'attachments', 'view' => 'attachments',
            'state' => ($data['ok'] ?? false) === true ? null : 'error',
            'blocks' => AttachmentsBlocks::build($data),
        ]);
    }
}
