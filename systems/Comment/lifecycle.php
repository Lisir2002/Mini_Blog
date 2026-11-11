<?php
/** 评论系统层自带的生命周期钩子（纯原生 PHP 闭包数组）；卸载时清理自建评论表。 */
return [
    'on_install'   => static function (array $ctx): void {},
    'on_upgrade'   => static function (array $ctx): void {},
    'on_uninstall' => static function (array $ctx): void {
        $configFile = dirname(__DIR__, 2) . '/config/app.php';
        if (!is_file($configFile)) return;
        $config = require $configFile;
        $db = is_array($config) ? (array) ($config['database'] ?? []) : [];
        if (($db['dsn'] ?? '') === '') return;
        try {
            $pdo = new \PDO((string) $db['dsn'], (string) ($db['user'] ?? ''), (string) ($db['pass'] ?? ''),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $prefix = (string) ($db['prefix'] ?? 'wx_');
            // 台账表与主表一起清：留着 orphan 的点赞 / 举报行，重装后会挂到新评论 id 上，
            // 变成「新评论一出生就有历史赞数」这种查不出来的脏数据。
            $pdo->exec('DROP TABLE IF EXISTS ' . $prefix . 'comment_votes');
            $pdo->exec('DROP TABLE IF EXISTS ' . $prefix . 'comment_reports');
            $pdo->exec('DROP TABLE IF EXISTS ' . $prefix . 'comments');
        } catch (\Throwable) {}
    },
];
