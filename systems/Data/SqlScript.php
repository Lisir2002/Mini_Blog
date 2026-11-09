<?php
declare(strict_types=1);
namespace Wx\Systems\Data;

use Wx\Contracts\DatabaseInterface;

/**
 * SQL 脚本解析 —— 只做三件事：替换表前缀、去掉整行注释、按分号切分语句。
 *
 * 抽成独立协作者的原因：建表兜底（Schema）与版本迁移（MigrationRunner）
 * 必须用同一套解析规则，否则同一条 SQL 在两条路径上的结果可能不同。
 */
final class SqlScript
{
    /** @return list<string> */
    public static function statements(DatabaseInterface $db, string $file): array
    {
        return self::parse($db, (string) file_get_contents($file));
    }

    /** 解析一段 SQL 文本（已读入内存的脚本同样适用，回退时不受文件被替换影响）。
     * @return list<string> */
    public static function parse(DatabaseInterface $db, string $raw): array
    {
        $raw = str_replace('wx_', $db->prefix(), $raw);
        $lines = array_filter(
            array_map('trim', explode("\n", $raw)),
            static fn(string $line): bool => $line !== '' && !str_starts_with($line, '--')
        );
        $out = [];
        foreach (explode(';', implode("\n", $lines)) as $statement) {
            $statement = trim($statement);
            if ($statement !== '') $out[] = $statement;
        }
        return $out;
    }

    public static function checksum(string $file): string
    {
        return (string) (hash_file('sha256', $file) ?: '');
    }
}
