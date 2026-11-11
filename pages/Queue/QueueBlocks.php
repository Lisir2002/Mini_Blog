<?php
declare(strict_types=1);
namespace Wx\Pages\Queue;

/**
 * 审核队列的区块组装 —— 纯函数，无 IO，可表驱动穷举。
 *
 * 三条呈现纪律：
 *  1. 「队列为空」与「队列不可用」不能长成同一张脸：前者是 200 + 明确文案，
 *     后者是 503 + 原因，绝不把取数失败渲染成「暂无待处理」。
 *  2. 被截断时必须写出「共 N 条、本页 M 条」，否则版主会以为处理完就清空了。
 *  3. 状态迁移按钮只出现三种合法去向；当前状态本身也在块里写明，
 *     让「为什么这条在队列里」不需要靠猜。
 */
final class QueueBlocks
{
    private const LABELS = [
        'pending'   => '待审核',
        'published' => '已通过',
        'hidden'    => '已隐藏',
        'spam'      => '垃圾',
        'deleted'   => '已删除',
    ];

    /** @param array<string, mixed> $queue @param array<string, mixed>|null $state */
    public static function build(array $queue, string $ticket, ?array $state, bool $degraded = false): array
    {
        $blocks = [self::notice($queue, $state, $degraded)];
        $blocks[] = ['type' => 'links', 'id' => 'queue-nav', 'title' => '管理入口', 'items' => [
            ['label' => '控制台', 'href' => '/console'],
            ['label' => '统计看板', 'href' => '/console/stats'],
        ]];

        if ($degraded) return $blocks;

        foreach ((array) ($queue['items'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $blocks[] = self::item($item, $ticket);
        }
        return $blocks;
    }

    /** @param array<string, mixed> $queue @param array<string, mixed>|null $state */
    private static function notice(array $queue, ?array $state, bool $degraded): array
    {
        $total = (int) ($queue['total'] ?? 0);
        $shown = (int) ($queue['shown'] ?? 0);

        if ($degraded) {
            return ['type' => 'notice', 'id' => 'queue-notice', 'tone' => 'warn', 'state' => 'unavailable',
                'title' => '队列读取失败',
                'value' => (string) ($state['message'] ?? '审核队列当前不可用；这不是「没有待处理项」。')];
        }
        if ($total === 0) {
            return ['type' => 'notice', 'id' => 'queue-notice', 'tone' => 'ok', 'state' => 'empty',
                'title' => '队列为空',
                'value' => '当前没有待处理或被举报的评论。这里是真的空了，不是读取失败。'];
        }
        $value = '共 ' . $total . ' 条待处理';
        if (!empty($queue['truncated'])) {
            $value .= '，本页显示 ' . $shown . ' 条（未列全，处理完请翻页或刷新）';
        }
        return ['type' => 'notice', 'id' => 'queue-notice', 'tone' => 'info', 'state' => 'ok',
            'title' => '按举报数排序', 'value' => $value . '。'];
    }

    /** @param array<string, mixed> $item */
    private static function item(array $item, string $ticket): array
    {
        $id     = (int) ($item['id'] ?? 0);
        $status = (string) ($item['status'] ?? '');
        $label  = self::LABELS[$status] ?? ($status === '' ? '未知' : $status);
        $slug   = (string) ($item['post_slug'] ?? '');

        $meta = '#' . $id . ' · ' . ((string) ($item['author'] ?? '') ?: '匿名')
            . ' · 举报 ' . (int) ($item['reports'] ?? 0)
            . ' · 点赞 ' . (int) ($item['likes'] ?? 0)
            . ' · 当前 ' . $label;

        // 票据签不出来时不给按钮：渲染一个点了必然 401 的按钮，比不给更糟。
        $fields = $ticket === '' ? [] : [
            ['tag' => 'submit', 'name' => 'to', 'value' => 'published', 'label' => '通过'],
            ['tag' => 'submit', 'name' => 'to', 'value' => 'hidden', 'label' => '隐藏'],
            ['tag' => 'submit', 'name' => 'to', 'value' => 'spam', 'label' => '标记垃圾'],
        ];

        return [
            'type'   => 'form',
            'title'  => $meta,
            'body'   => (string) ($item['body'] ?? ''),
            'action' => '/console/queue',
            'method' => 'post',
            'hidden' => [
                ['name' => '_form_ticket', 'value' => $ticket],
                ['name' => 'id', 'value' => (string) $id],
            ],
            'href'       => $slug !== '' ? '/post?slug=' . rawurlencode($slug) : '',
            'href_label' => '查看原文',
            'fields'     => $fields,
            'note'       => $fields === []
                ? '写入口当前不可用：票据密钥不可得，已记录到服务端日志。'
                : '提交后立即生效并回到本页；状态迁移只允许 published / hidden / spam。',
        ];
    }
}
