<?php
declare(strict_types=1);
namespace Wx\Pages\ContentAdmin;

/**
 * 回收站翻页链接 —— 纯函数，无 IO，只产通用区块协议里的 links 块。
 *
 * 从 ContentAdminBlocks 拆出来的原因与 FocusBlocks 相同：区块组装已经贴近
 * 职责预算，翻页是独立的一块呈现逻辑，不该挤进去。
 *
 * keyset 游标只能往前：接口不提供「上一页」的反向游标，因此这里只给
 * 「下一页」与「回到第一页」两个方向，绝不伪造一个算不出来的页码。
 * 游标是 base64 后的 id，可能含 + / =，必须 rawurlencode 后再拼进 query，
 * 否则 query string 会被截断或误读成参数分隔。
 */
final class ContentAdminPager
{
    private const PATH = '/console/content';

    /** 首页且没有后续时不产出任何翻页块，页面上不该出现一个空的导航条。 */
    public static function build(string $view, array $data): ?array
    {
        if ($view !== 'trash') return null;

        $cursor = (string) ($data['cursor'] ?? '');
        $next   = (string) ($data['next_cursor'] ?? '');
        $shown  = (int) ($data['shown'] ?? 0);
        $total  = (int) ($data['total'] ?? 0);

        $items = [];
        if ($cursor !== '') $items[] = ['label' => '← 回到第一页', 'href' => self::firstHref()];
        if ($next !== '') {
            $items[] = ['label' => '下一页 →', 'href' => self::nextHref($next),
                        'note' => '按 id 顺序继续'];
        }
        if ($items === []) return null;

        // 翻到一半把本页最后一条恢复 / 彻底删除，再刷新就会落在这里：
        // total 不受游标影响，仍是真实总数，所以不能显示成「回收站是空的」。
        $title = ($shown === 0 && $total > 0)
            ? '这一页已经空了（本页内容可能刚被恢复或彻底删除）'
            : '回收站翻页 · 本页 ' . $shown . ' / 共 ' . $total . ' 篇';

        return ['type' => 'links', 'id' => 'content-pager', 'title' => $title, 'items' => $items];
    }

    /** 非法游标是 422 降级态：显式给一条脱困链接，不留死路。 */
    public static function escape(?array $state): ?array
    {
        if ((string) ($state['phase'] ?? '') !== 'invalid') return null;
        return ['type' => 'links', 'id' => 'content-pager-escape', 'title' => '重新进入回收站',
            'items' => [['label' => '← 回到回收站第一页', 'href' => self::firstHref()]]];
    }

    public static function firstHref(): string
    {
        return self::PATH . '?view=trash';
    }

    public static function nextHref(string $cursor): string
    {
        return self::PATH . '?view=trash&cursor=' . rawurlencode($cursor);
    }
}
