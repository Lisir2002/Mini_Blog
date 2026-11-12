<?php
declare(strict_types=1);
namespace Wx\Pages\Queue;

/**
 * 按文章浏览面板 —— 补上「队列之外的评论」这条浏览路径。
 *
 * comments.queue 只收 pending 与有举报的条目，一篇正常文章的已发布评论不在里面；
 * 没有这条路径，删除接口就只能作用于「被举报的那几条」，闭环仍然是断的。
 */
final class QueuePostPanels
{
    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    public static function build(array $data): array
    {
        $out = [self::state($data)];
        $state = (string) ($data['comments_state'] ?? 'idle');
        if (!in_array($state, ['ok', 'empty'], true)) return $out;

        $ticket = (string) ($data['ticket'] ?? '');
        $canDelete = (bool) ($data['can_delete'] ?? false);
        $slug = (string) ($data['slug'] ?? '');
        $form = (array) ($data['form'] ?? []);
        foreach ((array) ($data['comments'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $id = (int) ($item['id'] ?? 0);
            $status = (string) ($item['status'] ?? '');
            $label = QueueItemBlocks::LABELS[$status] ?? ($status === '' ? '未知' : $status);
            $meta = '#' . $id . ' · ' . ((string) ($item['author'] ?? '') ?: '匿名')
                . ' · 第 ' . (int) ($item['depth'] ?? 0) . ' 层 · ' . $label
                . ' · 举报 ' . (int) ($item['reports'] ?? 0);
            $out[] = [
                'type' => 'form', 'title' => $meta,
                'body' => $status === 'deleted' ? '（已删除占位：正文与署名已清空）'
                    : (string) ($item['body'] ?? ''),
                'action' => '/console/queue', 'method' => 'post',
                'hidden' => QueueItemBlocks::hidden($ticket, $id, 'post', $slug),
                'fields' => [],
                'note' => '这里只读列出，状态迁移请回队列视图；删除用下面单独的确认表单。',
            ];
            $out[] = QueueItemBlocks::deleteOrNote($id, $status, $ticket, $canDelete, 'post', $slug, $form);
        }
        return $out;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function state(array $data): array
    {
        $slug = (string) ($data['slug'] ?? '');
        $state = (string) ($data['comments_state'] ?? 'idle');
        $postState = (string) ($data['post_state'] ?? 'idle');
        $shown = (int) ($data['comments_shown'] ?? 0);
        $total = (int) ($data['comments_total'] ?? 0);

        if ($slug === '' || $postState === 'none') {
            return self::block('queue-post-state', 'info', 'need-resource', '先选中一篇文章',
                '评论挂在文章上；上面从 content.posts 选一篇，才会用 comments.list 列出它的评论。');
        }
        if ($postState === 'missing' || $postState === 'failed') {
            return self::block('queue-post-state', 'warn', 'unavailable', '这篇文章读不到',
                'content.post 未能确认文章存在（' . (string) ($data['post_error'] ?? '') . '）。'
                . '页面拒绝渲染评论，也不会把「不知道」写成「没有评论」；写入口同时关闭。');
        }
        if ($state === 'failed') {
            return self::block('queue-post-state', 'warn', 'unavailable', '评论读取失败',
                (string) ($data['comments_fail'] ?? 'comments.list 不可用；这不是「没有评论」。'));
        }
        if ($state === 'empty') {
            return self::block('queue-post-state', 'ok', 'empty', '这篇文章确实没有评论',
                'comments.list 返回空清单，且 content.post 已确认文章存在 —— 这是真的空，不是读取失败。');
        }
        $value = '共 ' . $total . ' 条，本页列出 ' . $shown . ' 条（含已删除占位）。';
        if (!empty($data['comments_truncated'])) {
            $value .= ' 已截断，本页不是完整快照。';
        }
        return self::block('queue-post-state', 'info', 'ok', '当前文章：' . $slug, $value);
    }

    /** @return array<string, mixed> */
    private static function block(string $id, string $tone, string $state,
        string $title, string $value): array
    {
        return ['type' => 'notice', 'id' => $id, 'tone' => $tone,
            'state' => $state, 'title' => $title, 'value' => $value];
    }
}
