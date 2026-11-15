<?php
/**
 * 权限系统层自带的生命周期钩子 —— 纯原生 PHP 闭包数组。
 * 建表由 boot 阶段 ensureSchema() 幂等完成；卸载时只清理本层自建的 9 张表。
 * 「停用」不清数据，只有卸载才彻底清理 —— 权限策略不会因临时停用而丢失。
 */
return [
    'on_install' => static function (array $ctx): void {},
    'on_upgrade' => static function (array $ctx): void {},
    'on_uninstall' => static function (array $ctx): void {
        // 凭据经 Config 的 env 注入通道读取：app.php 只留空占位，卸载清理仍连得上库。
        $db = (array) (new \Wx\Core\Config(dirname(__DIR__, 2)))->get('database', []);
        if (($db['dsn'] ?? '') === '') return;
        try {
            $pdo = new \PDO(
                (string) $db['dsn'], (string) ($db['user'] ?? ''), (string) ($db['pass'] ?? ''),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            $prefix = (string) ($db['prefix'] ?? 'wx_');
            foreach (['permission_audit', 'permission_acl', 'permission_contract_overrides',
                  'permission_grants', 'permission_group_roles', 'permission_group_subjects',
                  'permission_groups', 'permission_role_parents', 'permission_roles'] as $name) {
                $pdo->exec('DROP TABLE IF EXISTS ' . $prefix . $name);
            }
        } catch (\Throwable) {
            // 数据库不可用时静默跳过：文件已由装卸系统移除，残留数据可人工清理
        }
    },
];
