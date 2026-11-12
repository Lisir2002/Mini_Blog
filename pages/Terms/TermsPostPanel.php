<?php
declare(strict_types=1);
namespace Wx\Pages\Terms;

/**
 * 当前文章面板 —— 展示它已挂了哪些术语，以及「读不到」时为什么不给写入口。
 *
 * `content.posts(status=any)` 会把草稿与定时文章列进链接，但 `content.post`
 * 对回收站 / 未到点的定时文章返回 40403。此时不能把「不知道」渲染成
 * 「没有术语」：那会诱使管理员提交一个空集合，把一篇看不见的文章清空。
 */
final class TermsPostPanel
{
    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    public static function build(array $data): array
    {
        $slug = (string) ($data['slug'] ?? '');
        $state = (string) ($data['post_state'] ?? 'idle');
        if ($slug === '') {
            return [['type' => 'notice', 'id' => 'terms-post-idle', 'tone' => 'info', 'state' => 'idle',
                'title' => '还没有选中文章',
                'value' => '上面的链接按 content.posts(status=any) 列出；选中后才能看到并修改这篇文章的分类与标签。']];
        }
        if ($state !== 'found') {
            return [['type' => 'notice', 'id' => 'terms-post-unreadable', 'tone' => 'warn',
                'state' => $state === 'missing' ? 'missing' : 'failed',
                'title' => '文章「' . $slug . '」读不到',
                'value' => 'content.post 返回 ' . (string) ($data['post_error'] ?? '无法读取')
                    . '。可能是在回收站、定时发布未到点，或当前身份无权访问。'
                    . '这里刻意不写「这篇文章没有术语」，也不提供挂载表单 —— 读不到就不能可靠地整体替换。']];
        }
        $post = (array) ($data['post'] ?? []);
        $out = [['type' => 'notice', 'id' => 'terms-post-current', 'tone' => 'info', 'state' => 'scope',
            'title' => '当前文章 · ' . $slug,
            'value' => (string) ($post['title'] ?? '') . ' · 状态 ' . (string) ($post['status'] ?? '')
                . '。下面的术语来自 content.post 返回的 terms 字段（v0.45 起写进契约 response_fields）；'
                . 'content.attach 整体替换后返回的 terms 也是同一口径。']];
        if ((string) ($data['attached_state'] ?? '') === 'unknown') {
            $out[] = ['type' => 'notice', 'id' => 'terms-attached-unknown', 'tone' => 'warn',
                'state' => 'unknown', 'title' => '拿不到当前已挂的术语',
                'value' => '响应里没有 terms 字段。页面不把「字段缺失」当成「没有术语」，因此关闭挂载表单。'];
            return $out;
        }
        $items = [];
        foreach ((array) ($data['attached'] ?? []) as $term) {
            $items[] = (string) ($term['kind'] ?? '') . ':' . (string) ($term['slug'] ?? '')
                . '（' . (string) ($term['name'] ?? '') . '）';
        }
        if ($items === []) {
            $out[] = ['type' => 'notice', 'id' => 'terms-attached-empty', 'tone' => 'info', 'state' => 'empty',
                'title' => '这篇文章还没有挂任何分类或标签',
                'value' => '接口正常返回 terms=[]，这是真的空，不是读不到。下面的挂载框也因此是空的。'];
            return $out;
        }
        $out[] = ['type' => 'list', 'id' => 'terms-attached', 'title' => '已挂术语（'
            . count($items) . ' 条）', 'items' => $items];
        return $out;
    }
}
