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
     * @param array<string, mixed>|null $state
     */
    public static function build(string $view, array $data, string $ticket,
        array $allowed, ?array $state, bool $degraded): array
    {
        $blocks = [self::notice($view, $data, $state, $degraded)];
        $blocks[] = ['type' => 'links', 'id' => 'content-nav', 'title' => '视图与管理入口', 'items' => [
            ['label' => '文章', 'href' => '/console/content', 'active' => $view !== 'trash'],
            ['label' => '写文章', 'href' => '/console/write'],
            ['label' => '角色授权', 'href' => '/console/roles'],
            ['label' => '回收站', 'href' => '/console/content?view=trash', 'active' => $view === 'trash'],
            ['label' => '审核队列', 'href' => '/console/queue'],
            ['label' => '统计看板', 'href' => '/console/stats'],
        ]];

        if ($degraded) return $blocks;

        foreach ((array) ($data['items'] ?? []) as $item) {
            if (is_array($item)) $blocks[] = self::row($view, $item, $ticket, $allowed);
        }
        return $blocks;
    }

    /** @param array<string, mixed> $data @param array<string, mixed>|null $state */
    private static function notice(string $view, array $data, ?array $state, bool $degraded): array
    {
        if ($degraded) {
            return ['type' => 'notice', 'id' => 'content-notice', 'tone' => 'warn', 'state' => 'unavailable',
                'title' => '内容列表读取失败',
                'value' => (string) ($state['message'] ?? '当前不可用；这不是「还没有文章」。')];
        }
        $total = (int) ($data['total'] ?? 0);
        $shown = (int) ($data['shown'] ?? 0);
        $where = $view === 'trash' ? '回收站' : '全部文章（含草稿与定时）';

        if ($total === 0) {
            return ['type' => 'notice', 'id' => 'content-notice', 'tone' => 'info', 'state' => 'empty',
                'title' => $view === 'trash' ? '回收站是空的' : '还没有文章',
                'value' => $view === 'trash'
                    ? '没有软删除的文章。这里空了，不代表列表也空。'
                    : '站内还没有任何文章。这里读取正常，只是数量为零。'];
        }
        $value = $where . '：共 ' . $total . ' 篇';
        if (!empty($data['truncated'])) $value .= '，本页只列前 ' . $shown . ' 篇（未列全）';
        return ['type' => 'notice', 'id' => 'content-notice', 'tone' => 'ok', 'state' => 'ok',
            'title' => '按最近更新排序', 'value' => $value . '。'];
    }

    /** @param array<string, mixed> $item @param array<string, bool> $allowed */
    private static function row(string $view, array $item, string $ticket, array $allowed): array
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
            'hidden' => [
                ['name' => '_form_ticket', 'value' => $ticket],
                ['name' => 'slug', 'value' => $slug],
            ],
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
