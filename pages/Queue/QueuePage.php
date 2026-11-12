<?php
declare(strict_types=1);
namespace Wx\Pages\Queue;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Contracts\PackageInterface;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;
use Wx\Pages\Shared\ConsoleTicket;

/**
 * 评论审核队列 —— 只产结构化数据，标签与样式全归主题的 admin 布局。
 *
 * v0.46 起从「只能改状态」扩成「能改状态 + 能删」，并补上按文章浏览：
 * 队列口径只收 pending 与有举报的条目，删一篇正常文章下的评论必须另有入口。
 *
 * 写路径的关键约束：页面守卫对非 GET 不读 Cookie（CSRF 边界），浏览器表单也无法
 * 附加 Authorization。因此 GET 时签发票据（绑定身份 / 权限 / 路径 / 20 分钟），
 * POST 由守卫验签后才进入本页。票据只证明「谁从哪一页提交」，删除权限仍由 Action
 * 现场问 comment.delete.any —— 页面直调 execute() 会绕过 API 分发器。
 */
final class QueuePage implements PackageInterface
{
    private const PATH = '/console/queue';
    private const PERMISSION = 'comment.moderate';
    public const TRANSITIONS = ['published', 'hidden', 'spam'];

    public function slug(): string { return 'queue'; }

    public function register(Kernel $kernel): void
    {
        $kernel->hooks()->on('route.resolve', function (Request $request, Response $response) use ($kernel): void {
            if ($request->path() !== self::PATH) return;
            $method = $request->method();
            if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
                $response->setStatus(405)->setHeader('Allow', 'GET, HEAD, POST')->setLayout('admin')
                    ->setPayload(['title' => '方法不支持', 'page' => 'queue', 'view' => 'queue',
                        'blocks' => [[
                            'type' => 'notice', 'id' => 'queue-method', 'tone' => 'warn',
                            'state' => 'denied', 'title' => '这个页面只接受 GET 与 POST',
                            'value' => '切换视图 / 选文章走查询参数，改动走带票据的表单提交。',
                        ]]]);
                return;
            }
            $identity = ConsoleTicket::identity($kernel, $request);
            if ($method === 'POST') {
                $this->submit($kernel, $request, $response, $identity);
                return;
            }
            $this->view($kernel, $request, $response, $identity, 200, null, []);
        }, 10);
    }

    /** @param array<string, mixed>|null $identity */
    private function submit(Kernel $kernel, Request $request, Response $response, ?array $identity): void
    {
        $registry = $this->registry($kernel);
        $acl = $this->acl($kernel);
        if ($registry === null) {
            $this->view($kernel, $request, $response, $identity, 503,
                ['status' => 503, 'code' => 50301, 'message' => '接口清单未装配，写操作不可用。'], $request->body());
            return;
        }
        $result = QueueAction::handle($registry, $request, self::TRANSITIONS, $identity, $acl);
        if (($result['ok'] ?? false) === true) {
            $query = [
                'saved'   => '1',
                'op'      => (string) ($result['op'] ?? ''),
                'outcome' => (string) ($result['outcome'] ?? ''),
                'changed' => ($result['changed'] ?? false) ? '1' : '0',
                'detail'  => (string) ($result['detail'] ?? ''),
                'hint'    => (string) ($result['hint'] ?? ''),
            ];
            $body = $request->body();
            $qs   = $request->query();
            $view = (string) ($body['view'] ?? $qs['view'] ?? '');
            $slug = (string) ($body['slug'] ?? $qs['slug'] ?? '');
            if (in_array($view, QueueQuery::VIEWS, true)) $query['view'] = $view;
            if ($slug !== '') $query['slug'] = $slug;
            // PRG：刷新不会重复处置，地址栏也不会停在 POST 结果上。
            $response->setStatus(303)->setHeader('Location', self::PATH . '?' . http_build_query($query));
            return;
        }
        $this->view($kernel, $request, $response, $identity,
            (int) ($result['status'] ?? 422), $result, $request->body());
    }

    /** @param array<string, mixed>|null $identity @param array<string, mixed>|null $failed
     *  @param array<string, mixed> $form */
    private function view(Kernel $kernel, Request $request, Response $response, ?array $identity,
        int $status, ?array $failed, array $form): void
    {
        $query = $request->query();
        $data = QueueQuery::fetch($kernel, $identity, $query);
        $data['ticket'] = ConsoleTicket::issue($kernel, $request, self::PERMISSION, self::PATH);
        $data['form'] = $form;
        $data['failed'] = $failed;
        $data['saved'] = (string) ($query['saved'] ?? '') === '1' ? $query : null;

        // 失败态必须自己固定 admin 布局：4xx 会被 render.layout 跳过路径映射，
        // 否则会被默认 site 槽位渲染成「带门户导航的空页面」，错误说明一个字看不到。
        $httpStatus = $failed !== null ? $status : (int) ($data['status'] ?? $status);
        // 失败详情由 QueueNotice 区块完整回显；这里只留一个标量标记，
        // 不重复塞 state 数组 —— 主题的 state.php 与 notice 会把同一句话说两遍。
        $errorState = $failed !== null || (string) ($data['error'] ?? '') !== '';
        $response->setStatus($httpStatus)->setLayout('admin')->setPayload([
            'title'       => '评论审核队列',
            'page'        => 'queue',
            'view'        => 'queue',
            'queue'       => (array) ($data['queue'] ?? []),
            'state'       => $errorState ? 'error' : null,
            'blocks'      => QueueBlocks::build($data),
            'form_ticket' => (string) $data['ticket'],
        ]);
    }

    private function registry(Kernel $kernel): ?ApiRegistryInterface
    {
        $c = $kernel->container();
        return $c->has(ApiRegistryInterface::class) ? $c->resolve(ApiRegistryInterface::class) : null;
    }

    /**
     * 用全限定名而非 use：本页已占满承载包 6 条 use 边预算，
     * 再为一次类型解析加一条 use 会超扇出；依赖本身仍然写全，不做隐式耦合。
     */
    private function acl(Kernel $kernel): ?\Wx\Contracts\AccessControlInterface
    {
        $c = $kernel->container();
        return $c->has(\Wx\Contracts\AccessControlInterface::class)
            ? $c->resolve(\Wx\Contracts\AccessControlInterface::class) : null;
    }
}
