<?php
declare(strict_types=1);
namespace Wx\Pages\Logs;

/**
 * 日志页的导航区块 —— 级别筛选与日期切换。
 *
 * 单独一类的理由不是「行数」，而是职责：这里产出的每个链接都必须是
 * **保留当前其它条件**的完整查询串。把 href 拼装和内容呈现混在一起时，
 * 很容易出现「切级别后日期丢了」这类问题，而且很难在测试里定位。
 */
final class LogsNav
{
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public static function levels(array $data): array
    {
        $counts = (array) ($data['levels'] ?? []);
        $items  = [['label' => '全部', 'href' => self::href($data, ''),
            'active' => (string) ($data['level'] ?? '') === '',
            'note' => '窗口内 ' . (int) ($data['returned'] ?? 0) . ' 行']];
        foreach (['ERROR', 'WARN', 'INFO', 'DEBUG', 'OTHER'] as $name) {
            $items[] = ['label' => $name, 'href' => self::href($data, strtolower($name)),
                'active' => strtolower((string) ($data['level'] ?? '')) === strtolower($name),
                'note' => (int) ($counts[$name] ?? 0) . ' 条'];
        }
        return ['type' => 'links', 'id' => 'log-levels',
            'title' => '按级别筛选（只筛本次窗口）', 'items' => $items];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public static function dates(array $data): array
    {
        $dates = (array) ($data['dates'] ?? []);
        $items = [];
        foreach (array_slice($dates, 0, 14) as $date) {
            // 切日期时保留级别与条数，否则「筛了 ERROR」会在点日期后静默丢失。
            $items[] = ['label' => (string) $date,
                'href' => self::link((string) $date, (int) ($data['limit'] ?? 20),
                    (string) ($data['level'] ?? '')),
                'active' => (string) $date === (string) ($data['date'] ?? '')];
        }
        return ['type' => 'links', 'id' => 'log-dates',
            'title' => $dates === [] ? '可切换的日期（无）' : '可切换的日期（最近 ' . count($items) . ' 天）',
            'items' => $items === [] ? [['label' => '没有任何日志文件',
                'href' => '/console/logs', 'note' => 'storage/logs 下没有 YYYY-MM-DD.log']] : $items];
    }

    /** @param array<string, mixed> $data */
    private static function href(array $data, string $level): string
    {
        return self::link((string) ($data['date'] ?? ''), (int) ($data['limit'] ?? 20), $level);
    }

    private static function link(string $date, int $limit, string $level): string
    {
        $q = ['date' => $date, 'limit' => (string) $limit];
        if ($level !== '') $q['level'] = $level;
        return '/console/logs?' . http_build_query($q);
    }
}
