<?php
declare(strict_types=1);
namespace Wx\Pages\Search;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Contracts\PackageInterface;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;

/**
 * 站内搜索 —— 只产结构化数据，标签与样式全归主题的 site 布局。
 *
 * 为什么单独一页而不是归档页的一个参数：归档是「按时间浏览」，搜索是「按词定位」，
 * 两者的状态语义不同（搜索有 idle 态，归档没有）。混在一起后，
 * 「没输入」与「没搜到」迟早会被写成同一张空列表。
 *
 * 关键词长度上限 80 字符：超长一律 422 并说明原因，不静默截断 ——
 * 截断后返回的结果与用户输入不对应，比直接拒绝更难排查。
 */
final class SearchPage implements PackageInterface
{
    private const PATH = '/search';
    private const MAX_QUERY = 80;

    public function slug(): string { return 'search'; }

    public function register(Kernel $kernel): void
    {
        $kernel->hooks()->on('route.resolve', function (Request $request, Response $response) use ($kernel): void {
            if ($request->path() !== self::PATH) return;

            $term = trim((string) (($request->query()['q'] ?? '')));
            if (self::length($term) > self::MAX_QUERY) {
                $this->emit($response, 422, $term, null, [
                    'title' => '关键词过长',
                    'message' => '关键词最多 ' . self::MAX_QUERY . ' 个字符，当前 ' . self::length($term)
                        . ' 个。请缩短后重试（未做静默截断，避免结果与输入不对应）。',
                ]);
                return;
            }

            $registry = $this->registry($kernel);
            if ($registry === null) {
                $this->emit($response, 503, $term, null, [
                    'title' => '搜索不可用', 'message' => '接口清单未装配，无法检索文章。',
                ]);
                return;
            }

            if ($term === '') {
                $this->emit($response, 200, '', null, null);
                return;
            }

            try {
                $result = (new SearchQuery($registry))->run($term);
            } catch (\Throwable $e) {
                $this->emit($response, 503, $term, null, [
                    'title' => '搜索失败', 'message' => $e->getMessage(),
                ]);
                return;
            }
            $this->emit($response, 200, $term, $result, null);
        }, 10);
    }

    /** @param array<string, mixed>|null $result @param array<string, mixed>|null $state */
    private function emit(Response $response, int $status, string $term,
        ?array $result, ?array $state): void
    {
        $response->setStatus($status)->setLayout('site')->setPayload([
            'title'  => $term === '' ? '搜索' : '搜索：' . $term,
            'page'   => 'search',
            'state'  => $state,
            'blocks' => SearchBlocks::build($term, $result, $state),
            'items'  => [],
        ]);
    }

    /** 部署不一定装了 mbstring；按字节退化的判定仅供上限校验，不用于截断。 */
    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function registry(Kernel $kernel): ?ApiRegistryInterface
    {
        return $kernel->container()->has(ApiRegistryInterface::class)
            ? $kernel->container()->resolve(ApiRegistryInterface::class) : null;
    }
}
