<?php
/**
 * 附件系统层自带的生命周期钩子（纯原生 PHP 闭包数组）。
 *
 * 卸载只删元数据表，**不删 storage/attachments 下的字节**：文件可能是用户唯一的原件，
 * 卸载一个系统层不该销毁用户资产。需要彻底清理时由站长显式执行回收流程。
 */
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
            $pdo->exec('DROP TABLE IF EXISTS ' . (string) ($db['prefix'] ?? 'wx_') . 'attachments');
        } catch (\Throwable) {}
    },
];
