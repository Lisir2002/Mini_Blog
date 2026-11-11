<?php
declare(strict_types=1);
namespace Wx\Pages\Archive;

use InvalidArgumentException;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Contracts\PackageInterface;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;

/**
 * 归档 / 标签云 / 术语详情 —— 只产结构化数据，不产出一个标签。
 *
 * 呈现归主题的 archive 布局（规矩 J），数据归既有公开接口 content.posts / content.terms，
 * 本页不新增接口、不新增系统层、不认识 SQL。主题没有 archive 槽位时，
 * PageSystem 会退化为纯 JSON，而不是渲染一个空白 HTML 壳子。
 */
final class ArchivePage implements PackageInterface
{
    private const ROUTES = ['/archive', '/terms', '/term'];

    public function slug(): string { return 'archive'; }

    public function register(Kernel $kernel): void
    {
        $kernel->hooks()->on('route.resolve', function (Request $request, Response $response) use ($kernel): void {
            $path = $request->path();
            if (!in_array($path, self::ROUTES, true)) return;

            $registry = $this->registry($kernel);
            if ($registry === null) {
                $this->emit($response, ArchiveState::failed(
                    'archive', 503, '归档不可用', '接口清单未装配，无法读取文章与术语。'));
                return;
            }

            $query = new ArchiveQuery($registry);
            $params = $request->query();
            try {
                $result = match ($path) {
                    '/archive' => $this->archive($query, $params),
                    '/terms'   => $this->terms($query),
                    default    => $this->term($query, $params),
                };
            } catch (\Throwable $e) {
                $result = ArchiveState::fromThrowable($this->viewOf($path), $e);
            }
            $this->emit($response, $result);
        }, 10);
    }

    /**
     * 4xx 响应会被 render.layout 钩子跳过「按路径映射布局」，
     * 因此失败态必须自己固定 archive 槽位 —— 否则会被默认 site 布局
     * 渲染成「带首页导航的空文章流」，错误说明一个字都看不到。
     * @param array{status: int, payload: array<string, mixed>} $result
     */
    private function emit(Response $response, array $result): void
    {
        $response->setStatus((int) $result['status'])->setLayout('archive');
        $response->setPayload((array) $result['payload']);
    }

    /** @param array<string, mixed> $query
     *  @return array{status: int, payload: array<string, mixed>} */
    private function archive(ArchiveQuery $q, array $query): array
    {
        try {
            $paged = (new ArchivePager($q))->fetch($query, '', '', '/archive');
        } catch (InvalidArgumentException $e) {
            // 非法筛选参数是读者的输入问题（422），不是站点故障（503）。
            // 「page=abc 悄悄当第 1 页」会让读者以为筛选生效了，实际请求已被改写。
            return ArchiveState::failed('archive', 422, '筛选参数不合法', $e->getMessage(),
                '示例：/archive?year=2026&month=11&page=2');
        }

        $terms = [];
        $termsState = null;
        try {
            $terms = array_map([ArchiveQuery::class, 'termRef'], $q->terms());
        } catch (\Throwable $e) {
            // 标签云是旁路：它挂了不能让整篇归档一起消失，但必须留一块显式说明。
            $termsState = ArchiveState::termsState($e);
        }

        $payload = ArchiveState::skeleton('归档', 'archive');
        $payload['sections']    = ArchiveQuery::groupByMonth($paged['items']);
        $payload['terms']       = $terms;
        $payload['terms_state'] = $termsState;
        ArchivePager::apply($payload, $paged, '/archive');
        return ['status' => 200, 'payload' => $payload];
    }

    /** @return array{status: int, payload: array<string, mixed>} */
    private function terms(ArchiveQuery $q): array
    {
        $terms = array_map([ArchiveQuery::class, 'termRef'], $q->terms());
        $payload = ArchiveState::skeleton('标签与分类', 'terms');
        $payload['terms'] = $terms;
        $payload['total'] = count($terms);
        $payload['site_total'] = count($terms);
        $payload['empty'] = $terms === [];
        return ['status' => 200, 'payload' => $payload];
    }

    /** @param array<string, mixed> $params
     *  @return array{status: int, payload: array<string, mixed>} */
    private function term(ArchiveQuery $q, array $params): array
    {
        $kind = trim((string) ($params['kind'] ?? ''));
        $slug = trim((string) ($params['slug'] ?? ''));
        if (!in_array($kind, ArchiveQuery::KINDS, true)) {
            return ArchiveState::failed('term', 422, '参数不合法',
                'kind 只能是 category 或 tag，当前为「' . ($kind === '' ? '(空)' : $kind) . '」。',
                '正确写法：/term?kind=tag&slug=xxx');
        }
        if ($slug === '') {
            return ArchiveState::failed('term', 422, '参数不合法', 'slug 不能为空。',
                '请从标签云进入，或补全 /term?kind=tag&slug=xxx');
        }

        // 先确认术语存在，再查文章。否则「拼错的标签」与「该标签下没有文章」
        // 都会渲染成同一个「暂无内容」，读者分不清是自己点错了还是站点真的空。
        $found = null;
        foreach ($q->terms() as $t) {
            if ((string) ($t['kind'] ?? '') === $kind && (string) ($t['slug'] ?? '') === $slug) {
                $found = ArchiveQuery::termRef($t);
                break;
            }
        }
        if ($found === null) {
            return ArchiveState::failed('term', 404, '术语不存在',
                '没有这个分类或标签：' . $kind . ':' . $slug,
                '可以从标签云重新进入；此处刻意不显示空列表。');
        }

        try {
            $paged = (new ArchivePager($q))->fetch($params, $slug, $kind, '/term');
        } catch (InvalidArgumentException $e) {
            return ArchiveState::failed('term', 422, '筛选参数不合法', $e->getMessage(),
                '筛选与翻页只改 year / month / page，kind 与 slug 保持原样。');
        }

        $payload = ArchiveState::skeleton(($kind === 'tag' ? '标签：' : '分类：') . $found['name'], 'term');
        $payload['term']     = $found;
        $payload['sections'] = [[
            'label' => $found['name'],
            'count' => count($paged['items']),
            'items' => array_map([ArchiveQuery::class, 'article'], $paged['items']),
        ]];
        ArchivePager::apply($payload, $paged, '/term');
        return ['status' => 200, 'payload' => $payload];
    }

    private function viewOf(string $path): string
    {
        return match ($path) {
            '/terms' => 'terms',
            '/term'  => 'term',
            default  => 'archive',
        };
    }

    private function registry(Kernel $kernel): ?ApiRegistryInterface
    {
        return $kernel->container()->has(ApiRegistryInterface::class)
            ? $kernel->container()->resolve(ApiRegistryInterface::class) : null;
    }
}
