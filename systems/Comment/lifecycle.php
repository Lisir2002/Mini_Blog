<?php
/** 评论系统层自带的生命周期钩子（纯原生 PHP 闭包数组）；卸载时清理自建评论表。 */
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
            $prefix = (string) ($db['prefix'] ?? 'wx_');
            // 台账表与主表一起清：留着 orphan 的点赞 / 举报行，重装后会挂到新评论 id 上，
            // 变成「新评论一出生就有历史赞数」这种查不出来的脏数据。
            $pdo->exec('DROP TABLE IF EXISTS ' . $prefix . 'comment_votes');
            $pdo->exec('DROP TABLE IF EXISTS ' . $prefix . 'comment_reports');
            $pdo->exec('DROP TABLE IF EXISTS ' . $prefix . 'comments');
        } catch (\Throwable) {}
    },
];
