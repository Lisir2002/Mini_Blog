<?php
declare(strict_types=1);
namespace Wx\Pages\Logs;

/**
 * 日志页的区块 —— 纯函数，无 IO。
 *
 * 四态必须分开，否则「没有日志」会被读成「日志为空」「过滤没命中」或「读不到」：
 *   bad-param / unavailable（失败） · missing（那天没文件） · empty（文件在但没行）
 *   · filtered-empty（窗口内没有该级别，不等于这一天没有）。
 *
 * 所有计数都限定在**本次返回的窗口**内，文案里写清楚。把「窗口内 0 条 ERROR」
 * 说成「今天没有错误」是这一页最容易犯、也最容易被相信的谎。
 */
final class LogsBlocks
{
    /**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    public static function build(array $data): array
    {
        if (($data['ok'] ?? false) !== true) {
            return [
                ['type' => 'notice', 'id' => 'log-error', 'tone' => 'warn',
                 'state' => (int) ($data['status'] ?? 503) === 422 ? 'bad-param' : 'unavailable',
                 'title' => (int) ($data['status'] ?? 503) === 422 ? '查询条件不合法' : '日志不可用',
                 'value' => (string) ($data['error'] ?? '未知原因')],
                self::boundary(),
            ];
        }

        // 三种「没有内容」各有各的原因，不共用一张空列表：
        // 没有这一天（missing）→ 文件在但没行（empty）→ 有行但窗口内没有该级别（filtered-empty）。
        if (($data['exists'] ?? false) !== true) {
            return [self::missing($data), LogsNav::dates($data), self::boundary()];
        }
        if ((int) ($data['total'] ?? 0) === 0) {
            return [self::emptyFile($data), LogsNav::dates($data), self::boundary()];
        }

        $blocks = [self::window($data), self::filterForm($data), LogsNav::levels($data)];
        $shown = self::filtered($data);
        $blocks[] = $shown === [] ? self::noMatch($data) : self::table($data, $shown);
        if ((bool) ($data['raw'] ?? false) && (array) ($data['raw_lines'] ?? []) !== []) {
            $blocks[] = ['type' => 'pre', 'id' => 'log-raw', 'title' => '原始行',
                'value' => implode("\n", (array) $data['raw_lines'])];
        }
        $blocks[] = LogsNav::dates($data);
        $blocks[] = self::boundary();
        return $blocks;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function window(array $data): array
    {
        $total    = (int) ($data['total'] ?? 0);
        $returned = (int) ($data['returned'] ?? 0);
        return ['type' => 'notice', 'id' => 'log-window',
            'tone' => (bool) ($data['truncated'] ?? false) ? 'warn' : 'ok',
            'state' => (bool) ($data['truncated'] ?? false) ? 'truncated' : 'ok',
            'title' => (string) ($data['date'] ?? '') . ' · 共 ' . $total . ' 行',
            'value' => (bool) ($data['truncated'] ?? false)
                ? '本次只取最近 ' . $returned . ' 行，更早的 ' . max(0, $total - $returned)
                    . ' 行没有取。要看得更早请加大条数（上限 200）。'
                : '全部 ' . $total . ' 行都在这里，没有被截断。'];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function missing(array $data): array
    {
        $dates = (array) ($data['dates'] ?? []);
        return ['type' => 'notice', 'id' => 'log-missing', 'tone' => 'warn', 'state' => 'missing',
            'title' => (string) ($data['date'] ?? '') . ' 没有日志文件',
            'value' => '这一天没有日志文件：日志只在站点跑过的那天写，不补、不回填。'
                . ($dates === []
                    ? '而且 storage 里一个日志文件都没有 —— 站点可能还没跑过，或者日志目录不可写。'
                    : '最近有日志的日期：' . implode('、', array_slice($dates, 0, 5)) . '，见下方日期切换。')];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function emptyFile(array $data): array
    {
        return ['type' => 'notice', 'id' => 'log-empty', 'tone' => 'info', 'state' => 'empty',
            'title' => '日志文件存在，但一行也没有',
            'value' => (string) ($data['date'] ?? '') . '.log 是空的：文件被创建了，但当天没有写入任何记录。'
                . '这与「没有这一天」不同 —— 文件在那儿，只是没内容。'];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function filterForm(array $data): array
    {
        return ['type' => 'form', 'id' => 'log-filter', 'title' => '按日期与条数查询',
            'action' => '/console/logs', 'method' => 'get',
            'body' => '日期切到别的日子，条数决定看尾部多少行（上限 200）。',
            'hidden' => (string) ($data['level'] ?? '') === '' ? []
                : [['name' => 'level', 'value' => (string) $data['level']]],
            'fields' => [
                ['tag' => 'input', 'id' => 'log-date', 'name' => 'date', 'type' => 'date',
                 'value' => (string) ($data['date'] ?? '')],
                ['tag' => 'input', 'id' => 'log-limit', 'name' => 'limit', 'type' => 'number',
                 'value' => (string) (int) ($data['limit'] ?? 20), 'placeholder' => '20 / 50 / 100 / 200'],
                ['tag' => 'submit', 'label' => '查看'],
            ],
            'note' => '只接受 ' . implode(' / ', LogsQuery::LIMITS) . ' 这几种条数；其它值会返回 422 而不是被静默改成 20。'];
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private static function filtered(array $data): array
    {
        $level = (string) ($data['level'] ?? '');
        $out = [];
        foreach ((array) ($data['entries'] ?? []) as $entry) {
            if (!is_array($entry)) continue;
            if ($level !== '' && strtolower((string) ($entry['level'] ?? '')) !== $level) continue;
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<array<string, mixed>> $shown
     * @return array<string, mixed>
     */
    private static function table(array $data, array $shown): array
    {
        $rows = [];
        foreach ($shown as $entry) {
            $level = (string) ($entry['level'] ?? 'OTHER');
            $time  = (string) ($entry['time'] ?? '');
            // 日期列是固定的，只留时分秒，行更窄也更好扫。
            $rows[] = [
                'k'    => $time === '' ? '(无时间戳)' : substr($time, 11),
                'v'    => $level,
                'ok'   => in_array($level, ['INFO', 'DEBUG'], true),
                'note' => (string) ($entry['message'] ?? ''),
            ];
        }
        $level = (string) ($data['level'] ?? '');
        return ['type' => 'table', 'id' => 'log-table',
            'title' => ($level === '' ? '全部级别' : strtoupper($level)) . ' · ' . count($rows) . ' 行',
            'rows' => $rows];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function noMatch(array $data): array
    {
        return ['type' => 'notice', 'id' => 'log-no-match', 'tone' => 'warn', 'state' => 'filtered-empty',
            'title' => '本次窗口里没有 ' . strtoupper((string) ($data['level'] ?? '')) . ' 级别的行',
            'value' => '取的是 ' . (string) ($data['date'] ?? '') . ' 最近 '
                . (int) ($data['returned'] ?? 0) . ' 行，这些行里没有这一级别。'
                . '这不等于那一天没有 —— 更早的行没有取（共 ' . (int) ($data['total'] ?? 0)
                . ' 行）。加大条数或看「全部」再确认。'];
    }

    /** @return array<string, mixed> */
    private static function boundary(): array
    {
        return ['type' => 'notice', 'id' => 'log-boundary', 'tone' => 'info', 'state' => 'boundary',
            'title' => '这一页的边界',
            'value' => '日志按天落在 storage/logs，每天一个文件；本页只读某一天的尾部，'
                . '不做跨天检索、不做全文搜索、不读归档。total 是文件行数，returned 是本次窗口 —— '
                . '两者一起看才知道自己看的是全部还是尾巴。日志内容原样呈现，页面不解释也不改写。'];
    }
}
