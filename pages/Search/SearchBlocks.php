<?php
declare(strict_types=1);
namespace Wx\Pages\Search;

use Wx\Systems\Content\PostRow;

/**
 * 搜索页的区块组装 —— 纯函数，无 IO。
 *
 * 四态纪律（同一张页面必须能区分）：
 *   idle     还没输入关键词：200 + 引导，绝不显示「没有找到」；
 *   empty    搜了但没命中：200 + 「没有匹配」并给出缩短关键词的建议；
 *   ok       有结果：写明「共 N 篇 / 本页 M 篇」，被截断时明确声明未列全；
 *   failed   取数失败：503 + 原因，绝不渲染成「没有结果」。
 */
final class SearchBlocks
{
    /** @param array<string, mixed>|null $result @param array<string, mixed>|null $state */
    public static function build(string $term, ?array $result, ?array $state): array
    {
        $blocks = [self::form($term)];

        if ($state !== null) {
            $blocks[] = ['type' => 'notice', 'id' => 'search-notice', 'tone' => 'warn',
                'state' => 'unavailable', 'title' => (string) ($state['title'] ?? '搜索不可用'),
                'value' => (string) ($state['message'] ?? '服务暂时不可用；这不是「没有结果」。')];
            return $blocks;
        }

        if ($term === '') {
            $blocks[] = ['type' => 'notice', 'id' => 'search-notice', 'tone' => 'info',
                'state' => 'idle', 'title' => '输入关键词开始搜索',
                'value' => '搜索范围是已发布文章的标题与正文；不区分大小写，% 与 _ 按普通字符匹配。'];
            return $blocks;
        }

        $total = (int) ($result['total'] ?? 0);
        $shown = (int) ($result['shown'] ?? 0);
        if ($total === 0) {
            $blocks[] = ['type' => 'notice', 'id' => 'search-notice', 'tone' => 'warn',
                'state' => 'empty', 'title' => '没有匹配的文章',
                'value' => '关键词「' . $term . '」在已发布文章里没有命中。换个更短的词，或去归档按时间浏览。'];
            $blocks[] = ['type' => 'links', 'id' => 'search-alt', 'title' => '也可以', 'items' => [
                ['label' => '按时间归档', 'href' => '/archive'],
                ['label' => '标签与分类', 'href' => '/terms'],
            ]];
            return $blocks;
        }

        $value = '共 ' . $total . ' 篇命中';
        if (!empty($result['truncated'])) {
            $value .= '，本页只列前 ' . $shown . ' 篇（未列全，请缩短关键词再搜）';
        }
        $blocks[] = ['type' => 'notice', 'id' => 'search-notice', 'tone' => 'ok',
            'state' => 'ok', 'title' => '关键词「' . $term . '」', 'value' => $value . '。'];

        $items = [];
        foreach ((array) ($result['items'] ?? []) as $post) {
            if (!is_array($post)) continue;
            $slug = (string) ($post['slug'] ?? '');
            if ($slug === '') continue;
            $items[] = [
                'label' => (string) ($post['title'] ?? '(无标题)'),
                'href'  => '/post?slug=' . rawurlencode($slug),
                'note'  => trim((string) ($post['excerpt'] ?? '')) !== ''
                    ? trim((string) $post['excerpt']) : PostRow::excerpt((string) ($post['body'] ?? '')),
            ];
        }
        $blocks[] = ['type' => 'list', 'id' => 'search-results',
            'title' => '搜索结果（按发布时间）', 'items' => $items];
        return $blocks;
    }

    private static function form(string $term): array
    {
        return [
            'type' => 'form', 'id' => 'search-form', 'title' => '搜索文章',
            'action' => '/search', 'method' => 'get',
            'fields' => [
                ['tag' => 'input', 'id' => 'q', 'name' => 'q', 'type' => 'search',
                 'value' => $term, 'placeholder' => '输入标题或正文里的词'],
                ['tag' => 'submit', 'label' => '搜索'],
            ],
            'note' => 'GET 提交：结果页可分享、可前进后退，无脚本也能用。',
        ];
    }
}
