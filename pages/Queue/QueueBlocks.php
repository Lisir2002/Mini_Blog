<?php
declare(strict_types=1);
namespace Wx\Pages\Queue;

/**
 * 审核队列区块编排 —— 纯函数，无 IO，可表驱动穷举。
 *
 * 呈现纪律：队列为空 / 队列读失败 / 被截断是三种状态；删除入口缺权限与票据签不出
 * 也要各自说清，不能塌成「暂无待处理」或「没有按钮」。两个视图共用同一份文章窗口。
 */
final class QueueBlocks
{
    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    public static function build(array $data): array
    {
        $blocks = QueueNotice::build($data);
        $blocks[] = ['type' => 'links', 'id' => 'queue-nav', 'title' => '管理入口', 'items' => [
            ['label' => '控制台', 'href' => '/console'],
            ['label' => '统计看板', 'href' => '/console/stats'],
        ]];
        $blocks[] = self::switcher($data);
        $blocks[] = self::posts($data);

        $view = (string) ($data['view'] ?? 'queue');
        if ($view === 'queue') {
            $blocks[] = self::queueState($data);
            foreach (QueueItemBlocks::items($data) as $b) $blocks[] = $b;
        } else {
            foreach (QueuePostPanels::build($data) as $b) $blocks[] = $b;
        }
        $blocks[] = self::boundary($data);
        return $blocks;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function switcher(array $data): array
    {
        $view = (string) ($data['view'] ?? 'queue');
        $slug = (string) ($data['slug'] ?? '');
        $postHref = '/console/queue?view=post' . ($slug !== '' ? '&slug=' . rawurlencode($slug) : '');
        return ['type' => 'links', 'id' => 'queue-views', 'title' => '两个视图', 'items' => [
            ['label' => $view === 'queue' ? '① 审核队列（当前）' : '① 审核队列',
                'href' => '/console/queue?view=queue'],
            ['label' => $view === 'post' ? '② 按文章浏览评论（当前）' : '② 按文章浏览评论',
                'href' => $postHref],
        ]];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function posts(array $data): array
    {
        $items = [];
        $slug = (string) ($data['slug'] ?? '');
        foreach ((array) ($data['posts'] ?? []) as $p) {
            if (!is_array($p)) continue;
            $s = (string) ($p['slug'] ?? '');
            if ($s === '') continue;
            $items[] = ['label' => ($s === $slug ? '▸ ' : '') . $s . ' · '
                . (string) ($p['title'] ?? '') . '（' . (string) ($p['status'] ?? '') . '）',
                'href' => '/console/queue?view=post&slug=' . rawurlencode($s)];
        }
        if ((string) ($data['posts_fail'] ?? '') !== '') {
            $items[] = ['label' => '文章清单读取失败：' . (string) $data['posts_fail'],
                'href' => '/console/queue?view=queue'];
        }
        return ['type' => 'links', 'id' => 'queue-posts',
            'title' => '选中一篇文章（最多 20 篇，不是全站总数）', 'items' => $items];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function queueState(array $data): array
    {
        $state = (string) ($data['queue_state'] ?? 'idle');
        $total = (int) ($data['queue_total'] ?? 0);
        $shown = (int) ($data['queue_shown'] ?? 0);
        if ($state === 'failed') {
            return self::block('queue-state', 'warn', 'unavailable', '队列读取失败',
                (string) ($data['queue_fail'] ?? 'comments.queue 不可用；这不是「没有待处理项」。'));
        }
        if ($state === 'empty' || $total === 0) {
            return self::block('queue-state', 'ok', 'empty', '队列为空',
                '当前没有待处理或被举报的评论。这里是真的空了，不是读取失败；'
                . '已发布的普通评论请用「按文章浏览」找。');
        }
        $value = '共 ' . $total . ' 条待处理，本页 ' . $shown . ' 条（按举报数排序）。';
        if (!empty($data['queue_truncated'])) $value .= ' 未列全，处理完请翻页或刷新。';
        return self::block('queue-state', 'info', 'ok', '审核队列', $value);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function boundary(array $data): array
    {
        $canDelete = (bool) ($data['can_delete'] ?? false);
        return self::block('queue-boundary', 'info', 'boundary', '边界与权限',
            '删除是软删且为终态：正文与署名清空、回复链保留，不能恢复也不能再审核；'
            . '重复删除回报「本来就已删除」，不报错也不重复写库。'
            . '页面声明 comment.moderate（读队列 / 状态迁移），删除动作现场问 '
            . ($canDelete ? 'comment.delete.any（当前有此权限）' : 'comment.delete.any（当前缺失，删除入口已关闭）')
            . '；comment.delete.own 是前台删自己评论的入口，本页不提供。'
            . '不做批量删除、不改历史正文；文章窗口 20 篇、评论窗口 60 条，都不是完整快照。');
    }

    /** @return array<string, mixed> */
    private static function block(string $id, string $tone, string $state,
        string $title, string $value): array
    {
        return ['type' => 'notice', 'id' => $id, 'tone' => $tone,
            'state' => $state, 'title' => $title, 'value' => $value];
    }
}
