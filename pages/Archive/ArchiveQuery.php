<?php
declare(strict_types=1);
namespace Wx\Pages\Archive;

use Wx\Contracts\ApiRegistryInterface;

/**
 * 归档数据协作者 —— 独立页里唯一与接口层打交道的地方。
 *
 * 三条不变量：
 *  1. 不自己造数据：文章、术语、计数全部来自既有公开接口 content.posts / content.terms，
 *     独立页不认识 SQL，也不复制系统层的筛选规则（定时发布、回收站、total 同口径）。
 *  2. 截断必须声明：列表单页上限 100，归档用游标翻到末尾；达到篇数 / 页数硬上限
 *     就置 truncated，绝不假装「已经全部」。total 仍是真实总数。
 *  3. 失败不能被翻译成空数组：接口抛错（尤其拔库后的 50101）原样向上抛，
 *     由页面转成显式 state。「没有文章」与「功能在当前部署下不可用」不能长得一样。
 */
final class ArchiveQuery
{
    public const PAGE_SIZE  = 100;
    public const MAX_POSTS  = 500;
    public const MAX_PAGES  = 10;
    public const KINDS      = ['category', 'tag'];

    public function __construct(private ApiRegistryInterface $registry) {}

    /** @return list<array<string, mixed>> */
    public function terms(): array
    {
        $r = $this->registry->execute('content.terms', ['kind' => 'any']);
        return array_values((array) ($r['items'] ?? []));
    }

    /** @return array{items: list<array<string, mixed>>, total: int, truncated: bool} */
    public function posts(string $termSlug = '', string $kind = ''): array
    {
        $items = [];
        $total = 0;
        $truncated = false;
        $cursor = '';
        $pages = 0;

        while ($pages < self::MAX_PAGES && count($items) < self::MAX_POSTS) {
            $input = ['status' => 'published', 'limit' => self::PAGE_SIZE, 'cursor' => $cursor];
            if ($termSlug !== '') $input['term'] = $termSlug;
            if ($kind !== '')     $input['kind'] = $kind;

            $r = $this->registry->execute('content.posts', $input);
            $page = array_values((array) ($r['items'] ?? []));
            $total = (int) ($r['total'] ?? 0);
            $pages++;

            foreach ($page as $post) {
                if (count($items) >= self::MAX_POSTS) {
                    $truncated = true;
                    break 2;
                }
                $items[] = is_array($post) ? $post : [];
            }

            $cursor = (string) ($r['next_cursor'] ?? '');
            if ($cursor === '') break;
        }
        // 正常读完时 cursor 必为空；非空说明撞上了篇数或页数上限。
        if ($cursor !== '') $truncated = true;

        return ['items' => $items, 'total' => $total, 'truncated' => $truncated];
    }

    /** @param list<array<string, mixed>> $posts
     *  @return list<array{label: string, count: int, items: list<array<string, mixed>>}> */
    public static function groupByMonth(array $posts): array
    {
        $buckets = [];
        foreach ($posts as $post) {
            $label = self::monthLabel((string) ($post['created_at'] ?? ''));
            $buckets[$label][] = self::article($post);
        }
        $out = [];
        foreach ($buckets as $label => $items) {
            $out[] = ['label' => $label, 'count' => count($items), 'items' => $items];
        }
        return $out;
    }

    /** 脏日期不能悄悄变成 1970 年 1 月，那会凭空多出一个分组。 */
    public static function monthLabel(string $date): string
    {
        $ts = strtotime($date);
        if ($ts === false) return '日期未知';
        return date('Y', $ts) . ' 年 ' . (int) date('n', $ts) . ' 月';
    }

    /** @param array<string, mixed> $post
     *  @return array{title: string, href: string, date: string, terms: list<array<string, string>>} */
    public static function article(array $post): array
    {
        $slug = (string) ($post['slug'] ?? '');
        $raw  = (string) ($post['created_at'] ?? '');
        $ts   = strtotime($raw);
        $terms = [];
        foreach ((array) ($post['terms'] ?? []) as $t) {
            if (!is_array($t)) continue;
            $kind = (string) ($t['kind'] ?? '');
            $tSlug = (string) ($t['slug'] ?? '');
            if (!in_array($kind, self::KINDS, true) || $tSlug === '') continue;
            $terms[] = [
                'name' => (string) ($t['name'] ?? $tSlug),
                'kind' => $kind,
                'href' => '/term?kind=' . rawurlencode($kind) . '&slug=' . rawurlencode($tSlug),
            ];
        }
        return [
            'title' => (string) ($post['title'] ?? '') !== '' ? (string) $post['title'] : '(无标题)',
            'href'  => $slug === '' ? '' : '/post?slug=' . rawurlencode($slug),
            'date'  => $ts === false ? $raw : date('Y-m-d', $ts),
            'terms' => $terms,
        ];
    }

    /** @param array<string, mixed> $term
     *  @return array{kind: string, slug: string, name: string, count: int, href: string} */
    public static function termRef(array $term): array
    {
        $kind = (string) ($term['kind'] ?? '');
        $slug = (string) ($term['slug'] ?? '');
        return [
            'kind'  => $kind,
            'slug'  => $slug,
            'name'  => (string) ($term['name'] ?? $slug),
            'count' => (int) ($term['post_count'] ?? 0),
            'href'  => ($kind === '' || $slug === '')
                ? '' : '/term?kind=' . rawurlencode($kind) . '&slug=' . rawurlencode($slug),
        ];
    }
}
