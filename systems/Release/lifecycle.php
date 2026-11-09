<?php
/**
 * release 层生命周期钩子 —— 只清理自己的迁移台账表。
 * 版本快照仓库归 lifecycle 管，这里不碰；避免两处都声称拥有同一份数据。
 */
return [
    'on_install' => static function (array $ctx): void {
        // 建表由 boot 阶段 ensureSchema() 幂等完成
    },
    'on_upgrade' => static function (array $ctx): void {
        // 迁移由 MigrationRunner 按台账执行，钩子里不做重复动作
    },
    'on_uninstall' => static function (array $ctx): void {
        $configFile = dirname(__DIR__, 2) . '/config/app.php';
        if (!is_file($configFile)) return;
        $config = require $configFile;
        if (!is_array($config)) return;
        $db = (array) ($config['database'] ?? []);
        if (($db['dsn'] ?? '') === '') return;
        try {
            $pdo = new \PDO(
                (string) $db['dsn'], (string) ($db['user'] ?? ''), (string) ($db['pass'] ?? ''),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            $pdo->exec('DROP TABLE IF EXISTS ' . (string) ($db['prefix'] ?? 'wx_') . 'schema_migrations');
        } catch (\Throwable) {
            // 数据库不可用时跳过：残留表可人工清理
        }
    },
];
