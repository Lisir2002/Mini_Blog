<?php
declare(strict_types=1);
namespace Wx\Pages\ContentAdmin;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Contracts\PackageInterface;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;
use Wx\Pages\Shared\ConsoleTicket;

/**
 * 内容管理台 —— 只产结构化数据，标签与样式归主题的 admin 布局。
 *
 * 这一页回答的是「站点能不能自己用」：此前 draft / published / archived / trash
 * 四态与五个生命周期接口都齐全，但页面上一个字都写不了，只能 curl。
 *
 * 写路径与审核队列同构：GET 签票据（绑定 content.read 与 /console/content），
 * POST 由页面守卫验签后才进入本页；非 GET 仍不读 Cookie。
 * 按钮再按 content.publish / content.delete 裁剪一层 —— 页面与接口做两次判定，
 * 前一次是为了不给假按钮，后一次才是真边界。
 */
final class ContentAdminPage implements PackageInterface
{
    private const PATH = '/console/content';
    private const PERMISSION = 'content.read';

    public function slug(): string { return 'content'; }

    public function register(Kernel $kernel): void
    {
        $kernel->hooks()->on('route.resolve', function (Request $request, Response $response) use ($kernel): void {
            if ($request->path() !== self::PATH) return;

            $registry = $this->registry($kernel);
            if ($registry === null) {
                $this->emit($kernel, $request, $response, 503, 'live',
                    ['items' => [], 'total' => 0, 'shown' => 0, 'truncated' => false],
                    ['title' => '内容管理不可用', 'message' => '接口清单未装配，无法读取文章列表。'], true);
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
        $view = (($request->query()['view'] ?? '') === 'trash') ? 'trash' : 'live';
        $focus = trim((string) ($request->query()['focus'] ?? ''));
        // 游标只在回收站视图生效；活文章列表没有翻页入口，带 cursor 也不读。
        $cursor = $view === 'trash' ? trim((string) ($request->query()['cursor'] ?? '')) : '';
        try {
            $query = new ContentAdminQuery($registry);
            $data = $view === 'trash' ? $query->trash($cursor) : $query->live();
        } catch (\InvalidArgumentException $e) {
            // 非法游标是请求问题（422），不是站点故障（503）。静默当成第一页
            // 会让站长以为翻页生效了，实际请求已被改写；这里显式报错并留脱困链接。
            $this->emit($kernel, $request, $response, 422, $view,
                ['items' => [], 'total' => 0, 'shown' => 0, 'truncated' => false],
                ['phase' => 'invalid', 'title' => '回收站翻页参数不合法',
                 'message' => $e->getMessage() . '；请回到回收站第一页重新翻。'], true);
            return;
        } catch (\Throwable $e) {
            $this->emit($kernel, $request, $response, 503, $view,
                ['items' => [], 'total' => 0, 'shown' => 0, 'truncated' => false],
                ['title' => '内容列表读取失败', 'message' => $e->getMessage()], true);
            return;
        }
        $this->emit($kernel, $request, $response, 200, $view, $data, null, false, $focus);
    }

    private function submit(Kernel $kernel, ApiRegistryInterface $registry,
        Request $request, Response $response): void
    {
        $body = $request->body();
        $op   = trim((string) ($body['op'] ?? ''));
        $identity = ConsoleTicket::identity($kernel, $request);
        $placement = in_array($op, ContentAdminPlacement::OPS, true);
        $result = $placement
            ? ContentAdminPlacement::run($kernel, $registry, $body, $identity)
            : ContentAdminAction::run($registry, $body, $identity);
        if (($result['ok'] ?? false) === true) {
            // PRG：成功后回到操作前的视图。排期 / 推荐位必须带回 focus，
            // 否则刷新后设置面板消失，刚设的值看不到，用户会以为没生效。
            $to = self::PATH;
            if ($op === 'restore' || $op === 'purge') {
                // 与 focus 同理：刷新后要留在原来那一页，否则深翻到第 3 页
                // 恢复一条就被弹回第 1 页，刚做的操作看不见。
                $to = self::PATH . '?view=trash';
                $cursor = trim((string) ($body['cursor'] ?? ''));
                if ($cursor !== '') $to .= '&cursor=' . rawurlencode($cursor);
            } elseif ($placement) {
                $to = self::PATH . '?focus=' . rawurlencode(trim((string) ($body['slug'] ?? '')));
            }
            $response->setStatus(303)->setHeader('Location', $to);
            return;
        }

        // purge 失败也属于回收站视图：此前误落到 live，失败态里会凭空换一个页面。
        $view = ($op === 'restore' || $op === 'purge') ? 'trash' : 'live';
        $focus = $placement ? trim((string) ($body['slug'] ?? '')) : '';
        $cursor = $view === 'trash' ? trim((string) ($body['cursor'] ?? '')) : '';
        $data = ['items' => [], 'total' => 0, 'shown' => 0, 'truncated' => false];
        try {
            $query = new ContentAdminQuery($registry);
            $data = $view === 'trash' ? $query->trash($cursor) : $query->live();
        } catch (\Throwable) {
            // 失败态里连列表都读不到：仍然渲染错误本身，不假装成功。
        }
        // 失败态必须自己固定 admin 布局：4xx 会被 render.layout 跳过路径映射，
        // 否则会落到 site 槽位，错误说明一个字都看不到。
        $this->emit($kernel, $request, $response, (int) ($result['status'] ?? 422), $view, $data, [
            'phase' => 'failed', 'code' => (int) ($result['code'] ?? 42201),
            'title' => '操作未完成', 'message' => (string) ($result['message'] ?? ''),
            'hint' => '列表已重新载入；票据有效期 20 分钟，过期请刷新本页。',
        ], false, $focus);
    }

    /** @param array<string, mixed> $data @param array<string, mixed>|null $state */
    private function emit(Kernel $kernel, Request $request, Response $response, int $status,
        string $view, array $data, ?array $state, bool $degraded, string $focus = ''): void
    {
        $identity = ConsoleTicket::identity($kernel, $request);
        $ticket = $degraded ? '' : ConsoleTicket::issue($kernel, $request, self::PERMISSION, self::PATH);
        $allowed = ContentAdminAction::allowed($kernel, $identity);
        $cap = ContentAdminPlacement::capabilities($kernel, $identity);

        $response->setStatus($status)->setLayout('admin')->setPayload([
            'title'  => $view === 'trash' ? '回收站' : '内容管理',
            'page'   => 'content',
            'view'   => 'content',
            'state'  => $state,
            'blocks' => ContentAdminBlocks::build($view, $data, $ticket, $allowed,
                $state, $degraded, $focus, $cap),
        ]);
    }

    private function registry(Kernel $kernel): ?ApiRegistryInterface
    {
        return $kernel->container()->has(ApiRegistryInterface::class)
            ? $kernel->container()->resolve(ApiRegistryInterface::class) : null;
    }
}
