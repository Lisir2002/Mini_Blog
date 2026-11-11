<?php
declare(strict_types=1);
namespace Wx\Pages\Write;

use Wx\Contracts\PackageInterface;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;
use Wx\Pages\Shared\ConsoleTicket;

/**
 * 写文章页 —— 内容闭环的最后一段：此前能改状态、能进回收站，但站上写不了正文。
 *
 * 新建与编辑共用一条路由：`/console/write` 与 `/console/write?slug=…`。
 * 页面声明 admin + content.create，是因为 content.create 接口本身就是
 * `auth=admin`；作者角色的入口要等接口契约调整，不在这里偷偷放宽。
 *
 * 写路径沿用票据：GET 签一张绑定 content.create 与 /console/write 的票，
 * POST 验签后进入本页；成功 303，失败留在原地并把正文原样带回表单。
 */
final class WritePage implements PackageInterface
{
    private const PATH = '/console/write';
    private const PERMISSION = 'content.create';

    public function slug(): string { return 'write'; }

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
        $result = WriteAction::run($kernel, $request, $identity);
        if (($result['ok'] ?? false) === true) {
            $slug = (string) ($result['slug'] ?? '');
            $saved = (string) ($result['mode'] ?? 'create') === 'edit' ? 'updated' : 'created';
            $response->setStatus(303)->setHeader('Location',
                self::PATH . '?slug=' . rawurlencode($slug) . '&saved=' . $saved);
            return;
        }

        $body = $request->body();
        $draft = [
            'slug' => trim((string) ($body['slug'] ?? '')),
            'title' => (string) ($body['title'] ?? ''),
            'body' => (string) ($body['body'] ?? ''),
            'status' => (string) ($body['status'] ?? ''),
            'author' => (string) (($identity ?? [])['username'] ?? ''),
        ];
        $this->view($kernel, $request, $response, $identity, (int) ($result['status'] ?? 422),
            $result, '', $draft);
    }

    /**
     * @param array<string, mixed>|null $identity
     * @param array<string, mixed>|null $error
     * @param array<string, mixed>|null $draft 提交失败时的表单值（优先于库里的旧内容）
     */
    private function view(Kernel $kernel, Request $request, Response $response, ?array $identity,
        int $status, ?array $error, string $notice, ?array $draft = null): void
    {
        $slug = trim((string) ($request->query()['slug'] ?? ($draft['slug'] ?? '')));
        $loaded = WriteQuery::load($kernel, $slug, $identity);
        $post = $draft ?? (array) $loaded['post'];
        if ($loaded['error'] !== '' && $draft === null) {
            $status = (int) $loaded['status'];
            $error = ['message' => $loaded['error']];
            $post = [];
        }

        $saved = (string) ($request->query()['saved'] ?? '');
        if ($notice === '' && $saved === 'created') $notice = '文章已创建，可以继续编辑。';
        if ($notice === '' && $saved === 'updated') $notice = '已保存。';

        $ticket = ConsoleTicket::issue($kernel, $request, self::PERMISSION, self::PATH);
        $response->setStatus($status)->setLayout('admin')->setPayload([
            'title'  => $slug === '' ? '写文章' : '编辑：' . $slug,
            'page'   => 'write',
            'view'   => 'write',
            'post'   => $post,
            'state'  => null,
            'blocks' => WriteBlocks::build($post, $ticket, $error, $notice, $status),
        ]);
    }
}
