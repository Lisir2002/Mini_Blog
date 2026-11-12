<?php
declare(strict_types=1);
namespace Wx\Pages\Queue;

/**
 * 队列条目的两张表单 —— 状态迁移与软删分开，因为它们是不同权限点、不同后果。
 *
 * 删除表单要求手写 id 确认：comments.delete 是软删且 deleted 为终态（不能恢复、
 * 不能再审核），误点一次就回不来。confirm 由 Action 校验，页面只负责把要求说清。
 */
final class QueueItemBlocks
{
    public const LABELS = [
        'pending' => '待审核', 'published' => '已通过', 'hidden' => '已隐藏',
        'spam' => '垃圾', 'deleted' => '已删除',
    ];

    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    public static function items(array $data): array
    {
        $ticket = (string) ($data['ticket'] ?? '');
        $canModerate = (bool) ($data['can_moderate'] ?? false);
        $canDelete = (bool) ($data['can_delete'] ?? false);
        $form = (array) ($data['form'] ?? []);
        $out = [];
        foreach ((array) ($data['queue'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $id     = (int) ($item['id'] ?? 0);
            $status = (string) ($item['status'] ?? '');
            $slug   = (string) ($item['post_slug'] ?? '');
            $label  = self::LABELS[$status] ?? ($status === '' ? '未知' : $status);
            $meta   = '#' . $id . ' · ' . ((string) ($item['author'] ?? '') ?: '匿名')
                . ' · 举报 ' . (int) ($item['reports'] ?? 0)
                . ' · 点赞 ' . (int) ($item['likes'] ?? 0) . ' · 当前 ' . $label;

            $fields = ($ticket !== '' && $canModerate && $status !== 'deleted') ? [
                ['tag' => 'submit', 'name' => 'to', 'value' => 'published', 'label' => '通过'],
                ['tag' => 'submit', 'name' => 'to', 'value' => 'hidden', 'label' => '隐藏'],
                ['tag' => 'submit', 'name' => 'to', 'value' => 'spam', 'label' => '标记垃圾'],
            ] : [];
            $out[] = [
                'type' => 'form', 'title' => $meta,
                'body' => (string) ($item['body'] ?? ''),
                'action' => '/console/queue', 'method' => 'post',
                'hidden' => self::hidden($ticket, $id, 'queue', ''),
                'href' => $slug !== '' ? '/post?slug=' . rawurlencode($slug) : '',
                'href_label' => '查看原文', 'fields' => $fields,
                'note' => $fields === []
                    ? '状态迁移入口当前不可用：票据或 comment.moderate 缺失；上面的内容仍然可读。'
                    : '提交后立即生效并回到本页；状态迁移只允许 published / hidden / spam。',
            ];
            $out[] = self::deleteOrNote($id, $status, $ticket, $canDelete, 'queue', '', $form);
        }
        return $out;
    }

    /** @param array<string, mixed> $form @return array<string, mixed> */
    public static function deleteOrNote(int $id, string $status, string $ticket,
        bool $canDelete, string $view, string $slug, array $form = []): array
    {
        if ($status === 'deleted') {
            return ['type' => 'notice', 'id' => 'q-deleted-' . $id, 'tone' => 'info',
                'state' => 'terminal', 'title' => '#' . $id . ' 已删除（占位）',
                'value' => '正文与署名已清空，只保留回复链位置；deleted 是终态，不再提供删除或审核入口。'];
        }
        if ($ticket === '' || !$canDelete) {
            return ['type' => 'notice', 'id' => 'q-no-delete-' . $id, 'tone' => 'info',
                'state' => 'permission-denied', 'title' => '#' . $id . ' 删除入口关闭',
                'value' => '删除要求 comment.delete.any（能审核不等于能删任意评论）；'
                    . '票据签不出时同样不给按钮 —— 渲染一个点了必然 401 的按钮更糟。'];
        }
        return self::deleteForm($id, $ticket, $view, $slug, $form);
    }

    /** @param array<string, mixed> $form @return array<string, mixed> */
    public static function deleteForm(int $id, string $ticket, string $view, string $slug,
        array $form = []): array
    {
        // 只回填被提交的那一条：失败态里五张表单同时显示同一个错值只会更乱。
        $refill = (string) ($form['id'] ?? '') === (string) $id
            ? (string) ($form['confirm'] ?? '') : '';
        return [
            'type' => 'form', 'title' => '删除评论 #' . $id . '（软删，终态）',
            'body' => '请手写这条评论的 id 再提交：删除后正文与署名清空、回复链保留，'
                . 'deleted 是终态，没有恢复入口，也不能再审核。',
            'action' => '/console/queue', 'method' => 'post',
            'hidden' => array_merge(self::hidden($ticket, $id, $view, $slug),
                [['name' => 'op', 'value' => 'delete']]),
            'fields' => [
                ['tag' => 'input', 'id' => 'q-confirm-' . $view . '-' . $id, 'name' => 'confirm',
                    'type' => 'text', 'value' => $refill, 'placeholder' => '手写评论 id ' . $id . ' 确认'],
                ['tag' => 'submit', 'label' => '确认软删'],
            ],
            'note' => '删除动作现场问 comment.delete.any；接口幂等，重复删除会回报「本来就已删除」。',
        ];
    }

    /** @return list<array<string, string>> */
    public static function hidden(string $ticket, int $id, string $view, string $slug): array
    {
        $out = [
            ['name' => '_form_ticket', 'value' => $ticket],
            ['name' => 'id', 'value' => (string) $id],
            ['name' => 'view', 'value' => $view],
        ];
        if ($slug !== '') $out[] = ['name' => 'slug', 'value' => $slug];
        return $out;
    }
}
