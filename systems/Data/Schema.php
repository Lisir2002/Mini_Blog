<?php
declare(strict_types=1);
namespace Wx\Systems\Data;

use Throwable;
use Wx\Contracts\DatabaseInterface;

/**
 * 系统层自建表的迁移加载器 —— 数据层内部工具。
 * SQL 文件归属于各系统层自己的 migrations/ 目录，占位前缀 wx_ 统一替换为实际配置值。
 * 核心完全不知道「建表」这件事的存在。
 *
 * 定位：这是「启动兜底」，保证任何一次启动表结构都可用；
 * 版本升级时由 release 层按迁移台账精确执行与回退（见 MigrationRunner）。
 *
 * v0.14 加了指纹闸门：目录内容没变就不再重放建表语句。
 * 原因是每次启动都跑 20+ 条 CREATE TABLE IF NOT EXISTS，幂等但不免费 ——
 * 每条都要拿元数据锁、解析、比对，而它们对结果没有任何影响。
 */
final class Schema
{
    private const STATE = 'schema_state';

    /**
     * 本进程内已确认过的「连接 + 目录」。
     * 必须带上连接身份：同进程里不同前缀 / 不同库的连接，结构是彼此独立的；
     * 只用目录做键，会让「换了库但代码没变」被误判成「已经建过」。
     */
    private static array $memo = [];

    /** 状态表是否已就绪（按连接记）—— 只建一次，避免每个目录都执行一条 DDL。 */
    private static array $ready = [];

    /** 按文件名顺序执行目录下全部 *.sql；忽略注释行与 *.down.sql；按分号切分语句。 */
    public static function apply(DatabaseInterface $db, string $dir): void
    {
        $key = self::key($db, $dir);
        $hash = self::fingerprint($dir);
        if (isset(self::$memo[$key]) && self::$memo[$key] === $hash) return;

        self::ensureState($db);
        if (self::stored($db, $dir) === $hash) {
            self::$memo[$key] = $hash;
            return;
        }

        foreach (glob($dir . '/*.sql') ?: [] as $file) {
            if (str_ends_with($file, '.down.sql')) continue;
            foreach (SqlScript::statements($db, $file) as $statement) {
                $db->pdo()->exec($statement);
            }
        }
        self::remember($db, $dir, $hash);
        self::$memo[$key] = $hash;
    }

    /**
     * 让指纹失效 —— 迁移执行器在 up / down 之后必须调用。
     *
     * 不调用会怎样：回退删掉一列，下次启动因为「目录没变」而跳过兜底，
     * 列就永远回不来。指纹描述的是「磁盘上的 SQL」，迁移改的是「库里的结构」，
     * 后者一变，前者的结论就不再成立。
     */
    public static function invalidate(DatabaseInterface $db): void
    {
        self::$memo = [];
        try {
            self::ensureState($db);
            $db->exec('DELETE FROM ' . self::table($db));
        } catch (Throwable $e) {
            // 库不可用时无事可做：下次能连上时指纹自然对不上或表会被重建。
        }
    }

    /** 目录内容指纹：文件名 + 内容哈希，顺序固定。 */
    private static function fingerprint(string $dir): string
    {
        $files = glob($dir . '/*.sql') ?: [];
        sort($files);
        $parts = [];
        foreach ($files as $file) {
            if (str_ends_with($file, '.down.sql')) continue;
            $parts[] = basename($file) . ':' . SqlScript::checksum($file);
        }
        return hash('sha256', implode('|', $parts));
    }

    private static function ensureState(DatabaseInterface $db): void
    {
        $id = spl_object_id($db);
        if (isset(self::$ready[$id])) return;
        $db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::table($db) . ' ('
            . 'dir VARCHAR(191) NOT NULL PRIMARY KEY,'
            . 'hash CHAR(64) NOT NULL,'
            . 'applied_at DATETIME NOT NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$ready[$id] = true;
    }

    private static function stored(DatabaseInterface $db, string $dir): string
    {
        try {
            $rows = $db->query('SELECT hash FROM ' . self::table($db) . ' WHERE dir = ? LIMIT 1', [$dir]);
        } catch (Throwable $e) {
            return '';
        }
        return (string) ($rows[0]['hash'] ?? '');
    }

    private static function remember(DatabaseInterface $db, string $dir, string $hash): void
    {
        try {
            $db->exec(
                'INSERT INTO ' . self::table($db) . ' (dir, hash, applied_at) VALUES (?, ?, ?)'
                . ' ON DUPLICATE KEY UPDATE hash = VALUES(hash), applied_at = VALUES(applied_at)',
                [$dir, $hash, date('Y-m-d H:i:s')]
            );
        } catch (Throwable $e) {
            // 记账失败不影响建表结果：最坏情况是下次多跑一遍幂等 DDL。
        }
    }

    private static function key(DatabaseInterface $db, string $dir): string
    {
        return spl_object_id($db) . '|' . $db->prefix() . '|' . $dir;
    }

    private static function table(DatabaseInterface $db): string
    {
        return $db->prefix() . self::STATE;
    }
}
