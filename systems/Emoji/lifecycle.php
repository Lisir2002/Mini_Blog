<?php
/** 表情系统层自带的生命周期钩子；卸载时只清理自定义表情表，内置表在代码里，无需处理。 */
return [
    'on_install'   => static function (array $ctx): void {},
    'on_upgrade'   => static function (array $ctx): void {},
    'on_uninstall' => static function (array $ctx): void {
        // 凭据经 Config 的 env 注入通道读取：app.php 只留空占位，卸载清理仍连得上库。
        $db = (array) (new \Wx\Core\Config(dirname(__DIR__, 2)))->get('database', []);
        if (($db['dsn'] ?? '') === '') return;
        try {
            $pdo = new \PDO((string) $db['dsn'], (string) ($db['user'] ?? ''), (string) ($db['pass'] ?? ''),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('DROP TABLE IF EXISTS ' . (string) ($db['prefix'] ?? 'wx_') . 'emojis');
        } catch (\Throwable) {}
    },
];
