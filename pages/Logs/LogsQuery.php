<?php
declare(strict_types=1);
namespace Wx\Pages\Logs;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Kernel;

/**
 * 日志页的取数 —— 只读 logs.query。
 *
 * 页面不拼日志文件路径：文件按日期平铺在 `storage/logs` 是日志系统的实现细节，
 * 页面只消费接口给出的 `dates`。这样日志系统换成 DB / Syslog 时这一页不用改。
 *
 * 参数一律先校验再调用：日期写错、条数不在允许集合里、级别拼错都返回 422，
 * 不静默回退成「今天 / 20 条 / 全部」—— 静默回退会让人以为查的是自己输入的那个条件。
 */
final class LogsQuery
{
    public const LIMITS = [20, 50, 100, 200];
    public const LEVELS = ['', 'debug', 'info', 'warn', 'error'];

    /**
     * @param array<string, mixed> $query
     * @return array{ok: bool, status: int, error: string, date: string, limit: int,
     *               level: string, raw: bool, exists: bool, total: int, returned: int,
     *               truncated: bool, dates: list<string>, entries: list<array<string, mixed>>,
     *               levels: array<string, int>, raw_lines: list<string>}
     */
    public static function fetch(Kernel $kernel, array $query): array
    {
        $date  = trim((string) ($query['date'] ?? date('Y-m-d')));
        $limit = (int) ($query['limit'] ?? 20);
        $level = strtolower(trim((string) ($query['level'] ?? '')));
        $raw   = (string) ($query['raw'] ?? '') === '1';

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1
            || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            return self::fail(422, '日期格式应为 YYYY-MM-DD，且必须是真实存在的日期。当前输入：'
                . ($date === '' ? '(空)' : $date));
        }
        if (!in_array($limit, self::LIMITS, true)) {
            return self::fail(422, '条数只能是 ' . implode(' / ', self::LIMITS) . '，当前为 ' . $limit . '。');
        }
        if (!in_array($level, self::LEVELS, true)) {
            return self::fail(422, '级别只能是 ' . implode(' / ', array_filter(self::LEVELS)) . '，当前为「'
                . $level . '」。');
        }

        $container = $kernel->container();
        if (!$container->has(ApiRegistryInterface::class)) {
            return self::fail(503, '接口清单未装配，无法读取日志。');
        }

        try {
            $data = $container->resolve(ApiRegistryInterface::class)
                ->execute('logs.query', ['date' => $date, 'limit' => $limit]);
        } catch (\Throwable $e) {
            return self::fail(503, '日志读取失败：' . $e->getMessage());
        }

        $lines   = array_values(array_map('strval', (array) ($data['lines'] ?? [])));
        $entries = array_map([self::class, 'parse'], $lines);
        $counts  = [];
        foreach ($entries as $entry) {
            $name = (string) $entry['level'];
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }
        ksort($counts);

        return [
            'ok'        => true,
            'status'    => 200,
            'error'     => '',
            'date'      => $date,
            'limit'     => $limit,
            'level'     => $level,
            'raw'       => $raw,
            'exists'    => (bool) ($data['exists'] ?? false),
            'total'     => (int) ($data['total'] ?? 0),
            'returned'  => (int) ($data['returned'] ?? count($lines)),
            'truncated' => (bool) ($data['truncated'] ?? false),
            'dates'     => array_values(array_map('strval', (array) ($data['dates'] ?? []))),
            'entries'   => $entries,
            'levels'    => $counts,
            'raw_lines' => $lines,
        ];
    }

    /**
     * 一行日志拆成时间 / 级别 / 正文。
     *
     * 认不出的行不丢弃、也不猜：级别标成 OTHER，正文保留原样。
     * 丢弃会让「日志里明明有这一行」变成无法解释的现象。
     *
     * @return array{time: string, level: string, message: string, raw: string}
     */
    public static function parse(string $line): array
    {
        if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+([A-Z]+)\s+(.*)$/u',
            $line, $m) === 1) {
            return ['time' => $m[1], 'level' => $m[2], 'message' => $m[3], 'raw' => $line];
        }
        return ['time' => '', 'level' => 'OTHER', 'message' => $line, 'raw' => $line];
    }

    /**
     * @return array{ok: false, status: int, error: string, date: string, limit: int,
     *               level: string, raw: bool, exists: bool, total: int, returned: int,
     *               truncated: bool, dates: list<string>, entries: list<array<string, mixed>>,
     *               levels: array<string, int>, raw_lines: list<string>}
     */
    private static function fail(int $status, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'error' => $message, 'date' => '',
            'limit' => 20, 'level' => '', 'raw' => false, 'exists' => false, 'total' => 0,
            'returned' => 0, 'truncated' => false, 'dates' => [], 'entries' => [],
            'levels' => [], 'raw_lines' => []];
    }
}
