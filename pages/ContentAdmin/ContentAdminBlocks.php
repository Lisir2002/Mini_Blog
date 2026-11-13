<?php
declare(strict_types=1);
namespace Wx\Pages\ContentAdmin;

/**
 * 内容管理的区块组装 —— 纯函数，无 IO。
 *
 * 呈现纪律：
 *  1. 两个视图（活文章 / 回收站）各有自己的空态文案：回收站空 = 没有删过东西，
 *     列表空 = 还没写过文章，二者不能共用一句「暂无内容」；
 *  2. 回收站里的每一条都必须能「恢复」；彻底删除需要人写出 slug（二次确认不代填）；
 *  3. 没有权限的动作不渲染按钮，改为一句话说明缺哪个权限点 —— 点了必然 403 的按钮比没有更糟。
 */
final class ContentAdminBlocks
{
    private const STATUS = [
        'draft' => '草稿', 'review' => '待审', 'published' => '已发布', 'archived' => '已归档',
    ];

    /**
     * @param array<string, mixed> $data @param array<string, bool> $allowed
     * @param array<string, mixed>|null $state @param array<string, bool> $cap
     */
    public static function build(string $view, array $data, string $ticket,
        array $allowed, ?array $state, bool $degraded,
        string $focus = '', array $cap = []): array
    {
        $pinned = ContentAdminPinned::active($data);
        $blocks = [self::notice($view, $data, $state, $degraded)];
        $nav = [
            ['label' => '文章', 'href' => '/console/content', 'active' => $view !== 'trash' && !$pinned],
            ['label' => '写文章', 'href' => '/console/write'],
            ['label' => '角色授权', 'href' => '/console/roles'],
            ['label' => '决策轨迹', 'href' => '/console/decisions'],
            ['label' => '系统状态', 'href' => '/console/system'],
            ['label' => '系统日志', 'href' => '/console/logs'],
            ['label' => '回收站', 'href' => '/console/content?view=trash', 'active' => $view === 'trash'],
            ['label' => '审核队列', 'href' => '/console/queue'],
            ['label' => '统计看板', 'href' => '/console/stats'],
        ];
        // 置顶切换项用 label/href/note（不是 active）：它是筛选开关，不是第三个视图。
        foreach (ContentAdminPinned::navItems($view, $pinned) as $item) $nav[] = $item;
        $blocks[] = ['type' => 'links', 'id' => 'content-nav', 'title' => '视图与管理入口', 'items' => $nav];
        $intro = ContentAdminPinned::intro($pinned);
        if ($intro !== null) $blocks[] = $intro;

        if ($degraded) {
            // 非法游标是读者输入问题，不能只留一句「列表读取失败」就断掉去路。
            $escape = ContentAdminPager::escape($state);
            if ($escape !== null) $blocks[] = $escape;
            return $blocks;
        }

        // 排期与推荐位放在本页而不是写作页：content.post 对「已发布但 publish_at
        // 未到点」的文章无条件 404，写作页读不到自己刚排期的文章；本页 status=any
        // 恰好能看见定时文章。面板组装交给独立协作者，避免本文件超出职责预算。
        if ($view !== 'trash') {
            foreach (ContentAdminFocusBlocks::build($focus, $data, $ticket, $cap) as $block) {
                $blocks[] = $block;
            }
        }

        $cursor = (string) ($data['cursor'] ?? '');
        foreach ((array) ($data['items'] ?? []) as $item) {
            if (is_array($item)) $blocks[] = self::row($view, $item, $ticket, $allowed, $cursor, $pinned);
        }
        // 翻页放在列表之后：先看本页内容，再决定要不要继续。
        $pager = ContentAdminPager::build($view, $data);
        if ($pager !== null) $blocks[] = $pager;
        return $blocks;
    }


    /** @param array<string, mixed> $data @param array<string, mixed>|null $state */
    private static function notice(string $view, array $data, ?array $state, bool $degraded): array
    {
        if ($degraded) {
            $invalid = (string) ($state['phase'] ?? '') === 'invalid';
            return ['type' => 'notice', 'id' => 'content-notice', 'tone' => 'warn',
                'state' => $invalid ? 'invalid' : 'unavailable',
                'title' => $invalid ? (string) ($state['title'] ?? '翻页参数不合法') : '内容列表读取失败',
                'value' => (string) ($state['message'] ?? '当前不可用；这不是「还没有文章」。')];
        }
        $total  = (int) ($data['total'] ?? 0);
        $shown  = (int) ($data['shown'] ?? 0);
        $cursor = (string) ($data['cursor'] ?? '');
        $next   = (string) ($data['next_cursor'] ?? '');
        $pinned = ContentAdminPinned::active($data);
        $where  = ContentAdminPinned::scope($view, $pinned);

        if ($total === 0) {
            $empty = ContentAdminPinned::emptyCopy($view, $pinned);
            return ['type' => 'notice', 'id' => 'content-notice', 'tone' => 'info', 'state' => 'empty',
                'title' => $empty['title'], 'value' => $empty['value']];
        }
        // total 不受游标影响，仍是全量；翻到后半段把本页清光时，
        // 「共 N 篇 / 本页 0 篇」必须说清是这一页空了，而不是回收站空了。
        if ($shown === 0 && $cursor !== '') {
            return ['type' => 'notice', 'id' => 'content-notice', 'tone' => 'warn', 'state' => 'page-empty',
                'title' => '这一页已经没有内容',
                'value' => $where . '仍有 ' . $total . ' 篇，但本页游标之后为空（可能刚被恢复或彻底删除）；请用下方「回到第一页」重新进入。'];
        }
        $value = $where . '：共 ' . $total . ' 篇';
        if ($next !== '') $value .= '，本页列 ' . $shown . ' 篇，可用下方「下一页」继续';
        elseif (!empty($data['truncated'])) $value .= '，本页只列前 ' . $shown . ' 篇（未列全）';
        return ['type' => 'notice', 'id' => 'content-notice', 'tone' => 'ok', 'state' => 'ok',
            'title' => $pinned ? '按权重倒序' : '按最近更新排序', 'value' => $value . '。'];
    }

    /** @param array<string, mixed> $item @param array<string, bool> $allowed */
    private static function row(string $view, array $item, string $ticket, array $allowed,
        string $cursor = '', bool $pinned = false): array
    {
        $slug   = (string) ($item['slug'] ?? '');
        $status = (string) ($item['status'] ?? '');
        $label  = self::STATUS[$status] ?? ($status === '' ? '未知' : $status);
        $title  = (string) ($item['title'] ?? '(无标题)');

        $meta = $title . ' · ' . $label;
        if (!empty($item['pinned'])) $meta .= ' · 置顶';
        if (($item['deleted_at'] ?? null) !== null) $meta .= ' · 删除于 ' . (string) $item['deleted_at'];
        if (($item['publish_at'] ?? null) !== null) $meta .= ' · 定时 ' . (string) $item['publish_at'];

        $fields = $ticket === '' ? [] : ($view === 'trash'
            ? self::trashButtons($slug, $allowed)
            : self::liveButtons($status, $allowed));

        return [
            'type' => 'form', 'title' => $meta, 'body' => 'slug：' . $slug,
            'action' => '/console/content', 'method' => 'post',
            // 恢复 / 彻底删除后 PRG 要回到原来那一页，游标随表单一起回传；
            // 首页没有游标就不塞空值，避免把「第 1 页」写成一个假游标。
            'hidden' => array_merge([
                ['name' => '_form_ticket', 'value' => $ticket],
                ['name' => 'slug', 'value' => $slug],
            ], ContentAdminPinned::hidden($pinned),
               ($view === 'trash' && $cursor !== '')
                ? [['name' => 'cursor', 'value' => $cursor]] : []),
            'href' => $view === 'trash' ? '' : '/post?slug=' . rawurlencode($slug),
            'href_label' => '查看文章',
            // 回收站里的文章不能编辑；列表页给出直达编辑器的入口，省掉手拼 URL。
            'href2' => $view === 'trash' ? '' : '/console/write?slug=' . rawurlencode($slug),
            'href2_label' => '编辑',
            'fields' => $fields,
            'note' => self::note($view, $ticket, $fields),
        ];
    }

    /** @param array<string, bool> $allowed */
    private static function liveButtons(string $status, array $allowed): array
    {
        $out = [];
        foreach ([['publish', '发布', 'published'], ['draft', '转草稿', 'draft'], ['archive', '归档', 'archived']] as [$op, $label, $state]) {
            if ($status === $state) continue;
            if (!($allowed[$op] ?? false)) continue;
            $out[] = ['tag' => 'submit', 'name' => 'op', 'value' => $op, 'label' => $label];
        }
        if ($allowed['trash'] ?? false) {
            $out[] = ['tag' => 'submit', 'name' => 'op', 'value' => 'trash', 'label' => '移入回收站'];
        }
        return $out === [] ? [] : $out;
    }

    /** @param array<string, bool> $allowed */
    private static function trashButtons(string $slug, array $allowed): array
    {
        $out = [];
        if ($allowed['restore'] ?? false) {
            $out[] = ['tag' => 'submit', 'name' => 'op', 'value' => 'restore', 'label' => '恢复'];
        }
        if ($allowed['purge'] ?? false) {
            // 二次确认必须人手输入：占位符里给出 slug，但不预填。
            $out[] = ['tag' => 'input', 'id' => 'confirm-' . substr(md5($slug), 0, 8),
                      'name' => 'confirm', 'type' => 'text', 'value' => '',
                      'placeholder' => '输入 ' . $slug . ' 确认彻底删除'];
            $out[] = ['tag' => 'submit', 'name' => 'op', 'value' => 'purge', 'label' => '彻底删除'];
        }
        return $out;
    }

    /** @param list<array<string, mixed>> $fields */
    private static function note(string $view, string $ticket, array $fields): string
    {
        if ($ticket === '') {
            return '写入口当前不可用：票据密钥不可得或身份未建立，已记录到服务端日志；请检查 storage 目录写权限。';
        }
        if ($fields === []) {
            return '当前身份没有本行的可用动作（发布需要 content.publish，删除 / 恢复需要 content.delete）。';
        }
        return $view === 'trash'
            ? '恢复只清删除标记，不会顺手发布；彻底删除不可恢复，需要手写 slug 确认。'
            : '发布 / 下架 / 归档走同一个状态机；不可达的迁移会被接口拒绝并说明原因。';
    }
}
