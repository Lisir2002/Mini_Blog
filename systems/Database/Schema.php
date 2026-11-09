<?php
declare(strict_types=1);
namespace Wx\Systems\Database;

use Wx\Contracts\DatabaseInterface;

/**
 * 系统层自建表的迁移加载器 —— 数据库系统层内部工具。
 * SQL 文件归属于各系统层自己的 migrations/ 目录，占位前缀 wx_ 统一替换为实际配置值。
 * 核心完全不知道「建表」这件事的存在。
 *
 * 定位：这是「启动兜底」，保证任何一次启动表结构都可用；
 * 版本升级时由 release 层按迁移台账精确执行与回退（见 MigrationRunner）。
 */
final class Schema
{
    /** 按文件名顺序执行目录下全部 *.sql；忽略注释行与 *.down.sql；按分号切分语句。 */
    public static function apply(DatabaseInterface $db, string $dir): void
    {
        foreach (glob($dir . '/*.sql') ?: [] as $file) {
            if (str_ends_with($file, '.down.sql')) continue;
            foreach (SqlScript::statements($db, $file) as $statement) {
                $db->pdo()->exec($statement);
            }
        }
    }
}
