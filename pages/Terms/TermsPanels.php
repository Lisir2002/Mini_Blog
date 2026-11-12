<?php
declare(strict_types=1);
namespace Wx\Pages\Terms;

/**
 * 分类与标签页的只读面板 —— 术语清单与文章窗口。
 *
 * 「还没有术语」「功能不可用」「清单读失败」是三种不同的状态，不能都塌成一句
 * 「没有分类」：content.terms 在内存降级模式返回 50101，而不是空数组。
 */
final class TermsPanels
{
    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    public static function build(array $data): array
    {
        return array_merge(self::terms($data), self::posts($data), TermsPostPanel::build($data));
    }

    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    private static function terms(array $data): array
    {
        $state = (string) ($data['terms_state'] ?? 'idle');
        $kind = (string) ($data['kind'] ?? 'any');
        $switch = ['type' => 'links', 'id' => 'terms-kind', 'title' => '按类型看术语', 'items' => []];
        foreach (['any' => '全部', 'category' => '分类', 'tag' => '标签'] as $key => $label) {
            $switch['items'][] = ['label' => $label, 'active' => $key === $kind,
                'href' => '/console/terms?' . http_build_query(['kind' => $key]
                    + ((string) ($data['slug'] ?? '') !== '' ? ['slug' => (string) $data['slug']] : []))];
        }
        $out = [$switch];
        if ($state === 'unavailable' || $state === 'failed') {
            $out[] = ['type' => 'notice', 'id' => 'terms-unavailable', 'tone' => 'warn',
                'state' => $state, 'title' => $state === 'unavailable' ? '分类与标签功能当前不可用' : '术语清单读取失败',
                'value' => '接口返回 ' . (string) ($data['terms_fail'] ?? '未知原因')
                    . '。需要 MySQL 存储两张术语表；内存降级模式返回 50101 而不是空数组，'
                    . '所以这里不是「还没有分类」，写入口也一并关闭。'];
            return $out;
        }
        if ($state === 'empty') {
            $out[] = ['type' => 'notice', 'id' => 'terms-empty', 'tone' => 'info', 'state' => 'empty',
                'title' => '当前筛选下一个术语都还没有',
                'value' => '接口正常返回，只是清单为空。用下面的表单新建第一个分类或标签。'];
            return $out;
        }
        $rows = [];
        foreach ((array) ($data['terms'] ?? []) as $term) {
            $rows[] = ['k' => (string) ($term['kind'] ?? '') . ':' . (string) ($term['slug'] ?? ''),
                'v' => (string) ($term['name'] ?? '') . '（ID ' . (int) ($term['id'] ?? 0) . '）',
                'ok' => true,
                'note' => (int) ($term['post_count'] ?? 0) . ' 篇文章 · '
                    . (string) ($term['description'] ?? '')];
        }
        $out[] = ['type' => 'table', 'id' => 'terms-table', 'title' => '术语清单（'
            . (int) ($data['terms_shown'] ?? 0) . ' / ' . (int) ($data['terms_total'] ?? 0) . ' 条）',
            'rows' => $rows];
        $out[] = ['type' => 'notice', 'id' => 'terms-count-note', 'tone' => 'info', 'state' => 'scope',
            'title' => '文章数怎么算',
            'value' => 'post_count 不含回收站文章：读者看到的标签云数字必须与点进去的列表条数一致。'
                . ((bool) ($data['terms_truncated'] ?? false)
                    ? ' 清单较长，这里只显示前 ' . (int) ($data['terms_shown'] ?? 0) . ' 条，未列全。'
                    : ' 清单已列全。')];
        return $out;
    }

    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    private static function posts(array $data): array
    {
        if ((string) ($data['posts_fail'] ?? '') !== '') {
            return [['type' => 'notice', 'id' => 'terms-posts-fail', 'tone' => 'warn', 'state' => 'failed',
                'title' => '文章清单读取失败', 'value' => (string) $data['posts_fail']]];
        }
        $items = [];
        foreach ((array) ($data['posts'] ?? []) as $post) {
            $slug = (string) ($post['slug'] ?? '');
            if ($slug === '') continue;
            $items[] = ['label' => (string) ($post['title'] ?? $slug), 'active' => $slug === (string) ($data['slug'] ?? ''),
                'href' => '/console/terms?slug=' . rawurlencode($slug),
                'note' => $slug . ' · ' . (string) ($post['status'] ?? '')];
        }
        if ($items === []) {
            return [['type' => 'notice', 'id' => 'terms-posts-empty', 'tone' => 'info', 'state' => 'empty',
                'title' => '文章窗口里没有文章', 'value' => 'content.posts(status=any) 没有返回条目。']];
        }
        $out = [['type' => 'links', 'id' => 'terms-posts', 'title' => '选中一篇文章 · 最多 '
            . TermsQuery::POST_LIMIT . ' 篇', 'items' => $items]];
        if ((bool) ($data['posts_truncated'] ?? false)) {
            $out[] = ['type' => 'notice', 'id' => 'terms-posts-window', 'tone' => 'info', 'state' => 'truncated',
                'title' => '这只是窗口，不是全站文章',
                'value' => '列表上限 ' . TermsQuery::POST_LIMIT . ' 篇，共 ' . (int) ($data['posts_total'] ?? 0)
                    . ' 篇。更早的文章不会出现在链接里；可以直接在地址栏写 ?slug= 打开。'];
        }
        return $out;
    }
}
