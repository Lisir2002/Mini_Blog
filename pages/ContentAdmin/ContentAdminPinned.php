<?php
declare(strict_types=1);
namespace Wx\Pages\ContentAdmin;

/**
 * 置顶位筛选的呈现协作者 —— 纯函数，无 IO。
 *
 * content.posts 自 0.4.0 起就有 pinned 查询筛选器（只取置顶推荐位、按
 * sort_weight 倒序、不混进 keyset 时间线），但页面一直只会在行里标一句
 * 「· 置顶」，从没按它取过列表：站长在 v0.53 设了推荐位，却看不到
 * 「当前有哪些推荐位、谁的权重更高」。本类把这次收口的呈现口径收在一处：
 * 导航切换、范围文案、空态文案、行表单 hidden、PRG 目标，避免散进主区块
 * 文件撑破职责预算（与 FocusBlocks / Pager 同一拆法）。
 *
 * 两条边界：
 *  1. 置顶是「推荐位」，不进时间线、不做 keyset 翻页（接口在 pinned=1 时
 *     next_cursor 恒为 null），所以这里不产任何翻页链接；
 *  2. 空态必须说「筛选结果为零」，不能说「站点没有文章」——两者不是一回事。
 */
final class ContentAdminPinned
{
    private const PATH = '/console/content';

    /** 筛选状态由取数结果带着走，页面不从 query 再读一遍，避免两套口径。 */
    public static function active(array $data): bool
    {
        return !empty($data['pinned']);
    }

    /** @return list<array<string, string>> 只在活文章视图产切换项 */
    public static function navItems(string $view, bool $pinned): array
    {
        if ($view !== 'live') return [];
        return $pinned
            ? [['label' => '全部文章', 'href' => self::PATH,
                'note' => '当前只列置顶推荐位，点此回到全部']]
            : [['label' => '只看置顶', 'href' => self::PATH . '?pinned=1',
                'note' => '按权重倒序，不含未置顶文章']];
    }

    /** @return list<array{name: string, value: string}> 写操作后 PRG 保留筛选 */
    public static function hidden(bool $pinned): array
    {
        return $pinned ? [['name' => 'pinned', 'value' => '1']] : [];
    }

    /** 非空时的范围口径：置顶视图必须点明排序依据与「不含未置顶」。 */
    public static function scope(string $view, bool $pinned): string
    {
        if ($view === 'trash') return '回收站';
        return $pinned
            ? '置顶推荐位（按权重倒序，不含未置顶文章）'
            : '全部文章（含草稿与定时）';
    }

    /** @return array{title: string, value: string} */
    public static function emptyCopy(string $view, bool $pinned): array
    {
        if ($view === 'trash') {
            return ['title' => '回收站是空的',
                'value' => '没有软删除的文章。这里空了，不代表列表也空。'];
        }
        if ($pinned) {
            return ['title' => '还没有置顶文章',
                'value' => '当前没有文章被设为推荐位。这是「筛选结果为零」，不是「站点没有文章」；切回全部文章即可看到全部内容。'];
        }
        return ['title' => '还没有文章',
            'value' => '站内还没有任何文章。这里读取正常，只是数量为零。'];
    }

    /** 置顶视图入口说明：解释筛选器与 v0.53 写入口的关系。 */
    public static function intro(bool $pinned): ?array
    {
        if (!$pinned) return null;
        return ['type' => 'notice', 'id' => 'content-pinned-intro', 'tone' => 'info',
            'state' => 'filtered',
            'title' => '当前视图：置顶推荐位',
            'value' => '列表走 content.posts 的 pinned=1，只取置顶文章并按 sort_weight 倒序。'
                . '推荐位不混进时间线（keyset 分页的键是 id），因此这里没有「下一页」；'
                . '置顶与权重在下方选中文章后设置，设完刷新本页即可看到排序变化。'];
    }

    /** 普通状态操作成功后留在原视图。 */
    public static function stayHref(bool $pinned): string
    {
        return $pinned ? self::PATH . '?pinned=1' : self::PATH;
    }

    /** 排期 / 推荐位操作后保留选中项与筛选状态，只追加必要的 query。 */
    public static function placementHref(string $slug, bool $pinned): string
    {
        $href = self::PATH . '?focus=' . rawurlencode($slug);
        return $pinned ? $href . '&pinned=1' : $href;
    }
}
