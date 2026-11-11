<?php
declare(strict_types=1);
namespace Wx\Pages\Archive;

/**
 * 归档分页协作者 —— 把「取数 → 过滤 → 分页 → 写回 payload」收敛成一处。
 *
 * 单独成类的原因是可测性：三个页面分支（归档 / 术语）共用同一套口径，
 * 守卫只要打这个类，就不必为每条路由各写一遍断言。
 *
 * 两条不变量：
 *  1. site_total（全站已发布数）与 total（当前筛选命中数）严格分开。
 *     v0.28 抓过「共 12 篇却一条也翻不到」，就是两个口径混用了同一个字段。
 *  2. 采样被硬上限截断时，筛选只覆盖已读取部分，必须置 filter_incomplete，
 *     不能假装「整个站点都筛过了」。
 */
final class ArchivePager
{
    public function __construct(private ArchiveQuery $query) {}

    /**
     * @param array<string, mixed> $input 页面收到的 query
     * @return array{items: list<array<string, mixed>>, filter: array<string, mixed>,
     *               page_no: int, pages: int, per_page: int, has_prev: bool, has_next: bool,
     *               beyond: bool, prev_href: string, next_href: string,
     *               total: int, site_total: int, sampled_truncated: bool, filter_incomplete: bool}
     * @throws \InvalidArgumentException 筛选参数非法（由页面转 422）
     */
    public function fetch(array $input, string $slug, string $kind, string $action): array
    {
        $f = ArchiveFilter::parse($input);
        $r = $this->query->posts($slug, $kind);
        $hit = ArchiveFilter::filter($r['items'], $f['year'], $f['month']);
        $p = ArchiveFilter::paginate($hit, $f['page'], $f['per_page']);
        $extra = $kind !== '' ? ['kind' => $kind, 'slug' => $slug] : [];
        $active = ArchiveFilter::active($f);
        // 越界页必须给「回得去」的出口：此时 has_prev 为真但 page-1 仍然越界，
        // 直接减一会把读者送到第 98 页 —— 所以退回最后一页。
        $prevHref = '';
        if ($p['beyond']) {
            $prevHref = ArchiveFilter::href($f, $p['pages'], $action, $extra);
        } elseif ($p['has_prev']) {
            $prevHref = ArchiveFilter::href($f, $f['page'] - 1, $action, $extra);
        }

        return [
            'items'    => $p['items'],
            'filter'   => ['year' => $f['year'], 'month' => $f['month'], 'active' => $active],
            'page_no'  => $f['page'],
            'pages'    => $p['pages'],
            'per_page' => $f['per_page'],
            'has_prev' => $p['has_prev'],
            'has_next' => $p['has_next'],
            'beyond'   => $p['beyond'],
            'prev_href' => $prevHref,
            'next_href' => $p['has_next'] ? ArchiveFilter::href($f, $f['page'] + 1, $action, $extra) : '',
            'total'      => $p['total'],
            'site_total' => $r['total'],
            'sampled_truncated' => $r['truncated'],
            'filter_incomplete' => $active && $r['truncated'],
        ];
    }

    /**
     * 把分页结果写回 payload。字段名集中在这里，改口径只需改一处。
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $paged
     */
    public static function apply(array &$payload, array $paged, string $action): void
    {
        $payload['site_total']        = $paged['site_total'];
        $payload['total']             = $paged['total'];
        $payload['shown']             = count($paged['items']);
        $payload['truncated']         = $paged['sampled_truncated'];
        $payload['filter_incomplete'] = $paged['filter_incomplete'];
        $payload['empty']             = $paged['items'] === [];
        $payload['filter']            = $paged['filter'];
        $payload['filter_action']     = $action;
        $payload['page_no']           = $paged['page_no'];
        $payload['pages']             = $paged['pages'];
        $payload['per_page']          = $paged['per_page'];
        $payload['has_prev']          = $paged['has_prev'];
        $payload['has_next']          = $paged['has_next'];
        $payload['beyond']            = $paged['beyond'];
        $payload['prev_href']         = $paged['prev_href'];
        $payload['next_href']         = $paged['next_href'];
    }
}
