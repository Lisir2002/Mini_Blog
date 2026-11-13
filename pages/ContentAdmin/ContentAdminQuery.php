<?php
declare(strict_types=1);
namespace Wx\Pages\ContentAdmin;

use Wx\Contracts\ApiRegistryInterface;

/**
 * 内容管理取数 —— 活文章与回收站两种视图，各自走既有接口。
 *
 * 两个视图不合并成一个查询：回收站里的文章状态原样保留（草稿仍是草稿），
 * 混进普通列表后「删掉的草稿」会被当成「草稿」计数，站长会以为它还在。
 *
 * v0.55：回收站接上接口早就提供的 keyset 游标。此前只取第一页 LIMIT 条，
 * total 更大时标一句「未列全」就结束 —— 第 21 篇往后永远翻不到。
 * 页面不自己解析游标：base64 / 数字校验只在 PostQuery 一处，非法值由它抛
 * InvalidArgumentException，页面翻成 422 显式状态，不静默当成第一页。
 */
final class ContentAdminQuery
{
    public const LIMIT = 20;
    /** 置顶位是有限的推荐位集合，不混进时间线、也不做 keyset 翻页；一次取够即可。 */
    public const PINNED_LIMIT = 50;

    public function __construct(private ApiRegistryInterface $registry) {}

    /**
     * $pinned 打开契约早就预留的 content.posts.pinned 筛选器：只取置顶推荐位，
     * 由系统层按 sort_weight 倒序返回。此前页面只会「显示这篇文章置顶了」，
     * 没有任何地方按置顶过滤 —— 设置了推荐位却看不到推荐位清单。
     * @return array{items: list<array<string, mixed>>, total: int, shown: int, truncated: bool, next_cursor: string, cursor: string}
     */
    public function live(string $keyword = '', bool $pinned = false): array
    {
        $input = ['status' => 'any', 'limit' => $pinned ? self::PINNED_LIMIT : self::LIMIT,
            'keyword' => $keyword];
        if ($pinned) $input['pinned'] = 1;
        return $this->shape($this->registry->execute('content.posts', $input), '', $pinned);
    }

    /** @return array{items: list<array<string, mixed>>, total: int, shown: int, truncated: bool, next_cursor: string, cursor: string} */
    public function trash(string $cursor = ''): array
    {
        $input = ['limit' => self::LIMIT];
        if ($cursor !== '') $input['cursor'] = $cursor;
        return $this->shape($this->registry->execute('content.trash', $input), $cursor);
    }

    /** @param array<string, mixed> $raw */
    private function shape(array $raw, string $cursor = '', bool $pinned = false): array
    {
        $items = array_values(array_filter((array) ($raw['items'] ?? []), 'is_array'));
        $total = (int) ($raw['total'] ?? count($items));
        return [
            'items'       => $items,
            'total'       => $total,
            'shown'       => count($items),
            'truncated'   => $total > count($items),
            // 游标必须原样带出：翻页链接与「下一页」判断都靠它，
            // 丢掉就退回 v0.54 那个「知道没列全却翻不动」的状态。
            'next_cursor' => (string) ($raw['next_cursor'] ?? ''),
            'cursor'      => $cursor,
            // 置顶筛选状态随数据一起走：区块组装靠它决定导航 active、空态文案
            // 与行表单的 hidden，不必给 build() 再塞第九个参数。
            'pinned'      => $pinned,
        ];
    }
}
