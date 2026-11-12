<?php
declare(strict_types=1);
namespace Wx\Pages\Terms;

use Wx\Contracts\PackageInterface;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;
use Wx\Pages\Shared\ConsoleTicket;

/**
 * 分类与标签台（v0.45）。
 *
 * 成功 303 回到同一篇文章并回报真实结果，失败绝不跳转且回填。
 * 页面声明 content.read；术语增删改问 content.manage_terms，文章挂载问 content.edit，
 * 票据只绑页面级权限点，不替代写裁决。
 */
final class TermsPage implements PackageInterface
{
    private const PATH = '/console/terms';
    private const PERMISSION = 'content.read';

    public function slug(): string { return 'terms'; }

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
                    'title' => '方法不支持', 'page' => 'terms', 'view' => 'terms',
                    'state' => 'deny', 'blocks' => [[
                        'type' => 'notice', 'id' => 'terms-method', 'tone' => 'warn',
                        'state' => 'denied', 'title' => '这个页面只接受 GET 与 POST',
                        'value' => '选文章 / 切分类走查询参数，改动走带票据的表单提交。',
                    ]],
                ]);
                return;
            }
            $this->view($kernel, $request, $response, $identity, 200, null, []);
        }, 10);
    }

    private function submit(Kernel $kernel, Request $request, Response $response, ?array $identity): void
    {
        $result = TermsAction::run($kernel, $request, $identity);
        if (($result['ok'] ?? false) === true) {
            $query = [
                'saved'   => '1',
                'op'      => (string) ($result['op'] ?? ''),
                'outcome' => (string) ($result['outcome'] ?? ''),
                'changed' => ($result['changed'] ?? false) ? '1' : '0',
                'detail'  => (string) ($result['detail'] ?? ''),
                'hint'    => (string) ($result['hint'] ?? ''),
            ];
            $slug = (string) ($result['slug'] ?? $request->query()['slug'] ?? '');
            if ($slug !== '') $query['slug'] = $slug;
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
        if ($error !== null && (string) ($query['slug'] ?? '') === ''
            && (string) ($form['slug'] ?? '') !== '') {
            // 表单 action 已带 slug；这里只在直接构造 POST 时补回上下文。
            $query['slug'] = (string) $form['slug'];
        }
        $data = TermsQuery::fetch($kernel, $identity, $query);
        $data['ticket'] = ConsoleTicket::issue($kernel, $request, self::PERMISSION, self::PATH);
        $data['form'] = $form;
        $data['saved'] = $query;
        if ($error !== null) $data['error'] = $error;

        $httpStatus = $error !== null ? $status : (int) ($data['status'] ?? $status);
        $response->setStatus($httpStatus)->setLayout('admin')->setPayload([
            'title' => '分类与标签', 'page' => 'terms', 'view' => 'terms',
            'state' => ($data['ok'] ?? false) === true ? null : 'error',
            'blocks' => TermsBlocks::build($data),
        ]);
    }
}
