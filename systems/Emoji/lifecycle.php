<?php
/** 表情系统层自带的生命周期钩子；卸载时只清理自定义表情表，内置表在代码里，无需处理。 */
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
            $pdo->exec('DROP TABLE IF EXISTS ' . (string) ($db['prefix'] ?? 'wx_') . 'emojis');
        } catch (\Throwable) {}
    },
];
