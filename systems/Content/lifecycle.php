<?php
/**
 * 内容系统层自带的生命周期钩子 —— 纯原生 PHP 闭包数组，无模板引擎、无 DSL、无注解。
 * 装卸系统只在约定时机 include 并调用，不解释闭包内容。
 *
 * 建表由系统层 boot 阶段的 ensureSchema() 幂等完成（CREATE TABLE IF NOT EXISTS）；
 * 这里负责卸载时清理本系统层自建的数据表，兑现「数据归属系统层、卸载才彻底清理」。
 */
return [
    'on_install' => static function (array $ctx): void {
        // 无需重复建表，boot 阶段会幂等执行 migrations/*.sql
    },
    'on_upgrade' => static function (array $ctx): void {
        // 版本迁移脚本位于 migrations/，由系统层按 from → to 顺序自行执行
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
            $pdo->exec('DROP TABLE IF EXISTS ' . (string) ($db['prefix'] ?? 'wx_') . 'content_posts');
        } catch (\Throwable) {
            // 数据库不可用时静默跳过：文件已由装卸系统移除，残留数据可人工清理
        }
    },
];
