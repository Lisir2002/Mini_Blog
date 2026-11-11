<?php
declare(strict_types=1);
namespace Wx\Pages\Queue;

use Wx\Pages\Shared\ConsoleTicket;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Contracts\PackageInterface;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;


/**
 * 评论审核队列 —— 只产结构化数据，标签与样式全归主题的 admin 布局。
 *
 * 这是「举报之后谁来处理」的落点。v0.29 起 comments.queue / comments.moderate
 * 就已完备，但没有任何页面消费它：举报进了队列，站上没人看得见 —— 一条断掉的闭环。
 *
 * 写路径的关键约束：页面守卫对非 GET 不读 Cookie（CSRF 边界），浏览器表单也无法
 * 附加 Authorization。因此 GET 时签发票据（绑定身份 / 权限 / 路径 / 20 分钟），
 * POST 由守卫验签后才进入本页。页面自身不碰 Cookie、不自造身份。
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

            $registry = $this->registry($kernel);
            if ($registry === null) {
                $this->emit($kernel, $request, $response, 503, [
                    'queue' => [], 'state' => ['phase' => 'failed', 'title' => '队列不可用',
                        'message' => '接口清单未装配，无法读取审核队列。'],
                ], true);
                return;
            }

            if ($request->method() === 'POST') {
                $this->submit($kernel, $registry, $request, $response);
                return;
            }
            $this->view($kernel, $registry, $request, $response);
        }, 10);
    }

    private function view(Kernel $kernel, ApiRegistryInterface $registry,
        Request $request, Response $response): void
    {
        try {
            $queue = (new QueueQuery($registry))->fetch();
        } catch (\Throwable $e) {
            $this->emit($kernel, $request, $response, 503, [
                'queue' => ['total' => 0, 'shown' => 0, 'truncated' => false, 'empty' => true],
                'state' => ['phase' => 'failed', 'code' => 50301, 'title' => '队列读取失败',
                    'message' => $e->getMessage()],
            ], true);
            return;
        }
        $this->emit($kernel, $request, $response, 200, ['queue' => $queue, 'state' => null], false);
    }

    private function submit(Kernel $kernel, ApiRegistryInterface $registry,
        Request $request, Response $response): void
    {
        $result = QueueAction::handle($registry, $request, self::TRANSITIONS,
            ConsoleTicket::identity($kernel, $request));
        if (($result['ok'] ?? false) === true) {
            // PRG：刷新不会重复处置，地址栏也不会停在 POST 结果上。
            $response->setStatus(303)->setHeader('Location', self::PATH);
            return;
        }

        $queue = [];
        try {
            $queue = (new QueueQuery($registry))->fetch();
        } catch (\Throwable) {
            // 失败态里连队列都读不到：仍然渲染错误，不假装成功。
        }
        // 失败态必须自己固定 admin 布局：4xx 会被 render.layout 跳过路径映射，
        // 否则会被默认 site 槽位渲染成「带门户导航的空页面」，错误说明一个字看不到。
        $this->emit($kernel, $request, $response, (int) ($result['status'] ?? 422), [
            'queue' => $queue,
            'state' => ['phase' => 'failed', 'code' => (int) ($result['code'] ?? 42201),
                'title' => '处置未完成', 'message' => (string) ($result['message'] ?? ''),
                'hint' => '页面已重新载入最新队列；票据有效期 20 分钟，过期请刷新本页。'],
        ], false);
    }

    /** @param array<string, mixed> $data */
    private function emit(Kernel $kernel, Request $request, Response $response,
        int $status, array $data, bool $degraded): void
    {
        $queue  = (array) ($data['queue'] ?? []);
        $state  = is_array($data['state'] ?? null) ? $data['state'] : null;
        $ticket = $degraded ? '' : ConsoleTicket::issue($kernel, $request, self::PERMISSION, self::PATH);

        $response->setStatus($status)->setLayout('admin')->setPayload([
            'title'       => '评论审核队列',
            'page'        => 'queue',
            'view'        => 'queue',
            'queue'       => $queue,
            'state'       => $state,
            'blocks'      => QueueBlocks::build($queue, $ticket, $state, $degraded),
            'form_ticket' => $ticket,
        ]);
    }

    private function registry(Kernel $kernel): ?ApiRegistryInterface
    {
        return $kernel->container()->has(ApiRegistryInterface::class)
            ? $kernel->container()->resolve(ApiRegistryInterface::class) : null;
    }
}
