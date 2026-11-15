<?php
/**
 * 成员系统层自带的生命周期钩子 —— 纯原生 PHP 闭包数组，无模板引擎、无 DSL。
 *
 * 建表由 boot 阶段的 ensureSchema() 幂等完成；这里只负责卸载时清空本层自建数据。
 * 头衔、经验、等级都是站点自有数据，卸载即彻底清理，符合「数据归属系统层」。
 */
return [
    'on_install' => static function (array $ctx): void {
        // 无需额外动作：boot 阶段会幂等执行 migrations/*.sql 并播种基线
    },
    'on_upgrade' => static function (array $ctx): void {
        // 版本迁移脚本位于 migrations/，由系统层按 from → to 顺序自行执行
    },
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
            foreach ([
                'member_user_titles', 'member_titles', 'member_xp_rules',
                'member_levels', 'member_xp_events', 'member_profiles',
            ] as $name) {
                $pdo->exec('DROP TABLE IF EXISTS ' . $prefix . $name);
            }
        } catch (\Throwable) {
            // 数据库不可用时静默跳过：文件已由装卸系统移除，残留数据可人工清理
        }
    },
];
