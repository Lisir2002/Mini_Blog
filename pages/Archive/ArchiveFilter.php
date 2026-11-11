<?php
declare(strict_types=1);
namespace Wx\Pages\Archive;

use InvalidArgumentException;

/**
 * 归档筛选协作者 —— 纯函数、无 IO，全部边界可表驱动穷举。
 *
 * 三条不变量：
 *  1. 非法参数一律显式抛异常（页面转 422），绝不静默夹紧或回落到默认。
 *     「page=abc 当成第 1 页」会让筛选看起来生效、实则悄悄改了用户的请求。
 *  2. total 与 items 同口径：过滤后 total 是命中数，不是全站总数；全站总数单独
 *     命名为 site_total，两者不混用（v0.28 抓过「共 12 篇却一条也翻不到」）。
 *  3. 采样被截断时显式声明「筛选可能不完整」：页面只在已读取的窗口内过滤，
 *     不能假装覆盖了全站所有文章。
 */
final class ArchiveFilter
{
    public const PER_PAGE_DEFAULT = 20;
    public const PER_PAGE_MAX = 50;
    public const PAGE_MAX = 10000;
    public const YEAR_MIN = 1970;
    public const YEAR_MAX = 2100;

    /** @return array{year: int|null, month: int|null, page: int, per_page: int} */
    public static function parse(array $query): array
    {
        return [
            'year'     => self::year((string) ($query['year'] ?? '')),
            'month'    => self::month((string) ($query['month'] ?? '')),
            'page'     => self::positive((string) ($query['page'] ?? ''), 'page', 1, self::PAGE_MAX),
            'per_page' => self::positive((string) ($query['per_page'] ?? ''), 'per_page',
                self::PER_PAGE_DEFAULT, self::PER_PAGE_MAX),
        ];
    }

    private static function year(string $raw): ?int
    {
        $v = trim($raw);
        if ($v === '') return null;
        if (preg_match('/^\d{4}$/', $v) !== 1) {
            throw new InvalidArgumentException('year 必须是 4 位数字，当前为「' . $v . '」');
        }
        $n = (int) $v;
        if ($n < self::YEAR_MIN || $n > self::YEAR_MAX) {
            throw new InvalidArgumentException('year 超出允许范围（' . self::YEAR_MIN . '~' . self::YEAR_MAX . '）');
        }
        return $n;
    }

    private static function month(string $raw): ?int
    {
        $v = trim($raw);
        if ($v === '') return null;
        if (preg_match('/^\d{1,2}$/', $v) !== 1) {
            throw new InvalidArgumentException('month 必须是 1~12 的数字，当前为「' . $v . '」');
        }
        $n = (int) $v;
        if ($n < 1 || $n > 12) {
            throw new InvalidArgumentException('month 超出允许范围（1~12），当前为 ' . $n);
        }
        return $n;
    }

    private static function positive(string $raw, string $field, int $default, int $max): int
    {
        $v = trim($raw);
        if ($v === '') return $default;
        if (preg_match('/^\d+$/', $v) !== 1) {
            throw new InvalidArgumentException($field . ' 必须是正整数，当前为「' . $v . '」');
        }
        $n = (int) $v;
        if ($n < 1 || $n > $max) {
            throw new InvalidArgumentException($field . ' 超出允许范围（1~' . $max . '），当前为 ' . $n);
        }
        return $n;
    }

    /** 是否启用了时间范围筛选（影响「全站 / 命中」口径的措辞） */
    public static function active(array $f): bool
    {
        return $f['year'] !== null || $f['month'] !== null;
    }

    /** @param list<array<string, mixed>> $items @return list<array<string, mixed>> */
    public static function filter(array $items, ?int $year, ?int $month): array
    {
        if ($year === null && $month === null) return $items;
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $ts = strtotime((string) ($item['created_at'] ?? ''));
            if ($ts === false) continue;   // 脏日期不参与筛选，但也不伪装成命中
            if ($year !== null && (int) date('Y', $ts) !== $year) continue;
            if ($month !== null && (int) date('n', $ts) !== $month) continue;
            $out[] = $item;
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array{items: list<array<string,mixed>>, total: int, pages: int,
     *               has_prev: bool, has_next: bool, beyond: bool}
     */
    public static function paginate(array $items, int $page, int $perPage): array
    {
        $total = count($items);
        $pages = max(1, (int) ceil($total / max(1, $perPage)));
        $offset = ($page - 1) * $perPage;
        $slice = array_slice($items, $offset, $perPage);
        return [
            'items'    => $slice,
            'total'    => $total,
            'pages'    => $pages,
            'has_prev' => $page > 1,
            'has_next' => $offset + count($slice) < $total,
            // 越界页是合法状态但必须显式：否则「空列表」会被误读成「没有文章」。
            'beyond'   => $page > $pages,
        ];
    }

    /**
     * 分页链接保留当前筛选条件，避免翻一页就把筛选（以及术语）丢了。
     * @param array<string, string|int> $extra 术语页要带上 kind / slug
     */
    public static function href(array $f, int $page, string $base = '/archive', array $extra = []): string
    {
        $q = ['page' => $page];
        if ($f['year'] !== null) $q['year'] = $f['year'];
        if ($f['month'] !== null) $q['month'] = $f['month'];
        if ($f['per_page'] !== self::PER_PAGE_DEFAULT) $q['per_page'] = $f['per_page'];
        return $base . '?' . http_build_query($q + $extra);
    }
}
