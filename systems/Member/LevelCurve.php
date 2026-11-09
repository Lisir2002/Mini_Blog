<?php
declare(strict_types=1);
namespace Wx\Systems\Member;

/**
 * 等级曲线 —— 纯计算，不读库、不发事件，可脱离存储独立验证。
 *
 * 等级不是一份独立状态，而是「经验 + 当前门槛表」的解释结果：
 * 站长改了门槛，所有人下次读取时就会落到新等级；这也让「重算」天然幂等。
 * 门槛为空时退化为单级（level 1），保证系统永远有可展示的等级。
 */
final class LevelCurve
{
    public function role(): string { return 'level_curve'; }

    /** @param list<array<string, mixed>> $levels @return list<array<string, mixed>> */
    public static function normalize(array $levels): array
    {
        $out = [];
        foreach ($levels as $row) {
            $level = (int) ($row['level'] ?? 0);
            if ($level < 1) continue;
            $out[] = [
                'level'   => $level,
                'name'    => (string) ($row['name'] ?? ('Lv.' . $level)),
                'min_xp'  => max(0, (int) ($row['min_xp'] ?? 0)),
                'color'   => (string) ($row['color'] ?? ''),
            ];
        }
        usort($out, static fn(array $a, array $b): int =>
            ($a['min_xp'] <=> $b['min_xp']) ?: ($a['level'] <=> $b['level'])
        );
        return $out;
    }

    /** @param list<array<string, mixed>> $levels */
    public static function levelOf(array $levels, int $xp): array
    {
        $rows = self::normalize($levels);
        if ($rows === []) {
            return ['level' => 1, 'name' => 'Lv.1', 'min_xp' => 0, 'color' => ''];
        }
        $current = $rows[0];
        foreach ($rows as $row) {
            if ($row['min_xp'] <= $xp) $current = $row;
        }
        return $current;
    }

    /**
     * @param list<array<string, mixed>> $levels
     * @return array{current: array<string, mixed>, next: array<string, mixed>|null,
     *               xp_to_next: int, percent: int}
     */
    public static function progress(array $levels, int $xp): array
    {
        $rows    = self::normalize($levels);
        $current = self::levelOf($rows, $xp);
        $next    = null;
        foreach ($rows as $row) {
            if ($row['min_xp'] > $xp) { $next = $row; break; }
        }
        if ($next === null) {
            return ['current' => $current, 'next' => null, 'xp_to_next' => 0, 'percent' => 100];
        }
        $span = $next['min_xp'] - $current['min_xp'];
        $done = $xp - $current['min_xp'];
        $pct  = $span > 0 ? (int) floor($done / $span * 100) : 0;
        return [
            'current'    => $current,
            'next'       => $next,
            'xp_to_next' => max(0, $next['min_xp'] - $xp),
            'percent'    => max(0, min(99, $pct)),
        ];
    }
}
