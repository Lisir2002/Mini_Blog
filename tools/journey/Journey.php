<?php
declare(strict_types=1);
namespace Wx\Tools\Journey;

/**
 * 全链路旅程编排 —— 一份执行器，两处消费：
 *   php tools/journey.php        CLI 输出纯文本证据
 *   tests/groups/26a-*.php       守卫直接调用 run()，断言同一份真实结果
 *
 * 刻意不启子进程、不做静态检查：守卫与 CLI 跑的是完全相同的请求序列。
 */
final class Journey
{
    /** @return array{lines:list<string>, passed:int, failed:int, residue:list<string>, facts:array} */
    public static function run(string $root): array
    {
        $site = new Site($root);
        $p = new Probe();
        $s = new JourneyState();
        $residue = [];

        try {
            $p->step('环境：数据库可达（旅程要求真实 MariaDB）', function () use ($p, $site): void {
                $cfg = require $site->root() . '/config/app.php';
                $db = (array) ($cfg['database'] ?? []);
                $dsn = (string) ($db['dsn'] ?? '');
                $p->ok($dsn !== '', '主配置未登记 DSN');
                try {
                    $pdo = new \PDO($dsn, (string) ($db['user'] ?? ''), (string) ($db['pass'] ?? ''));
                    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                    $pdo->query('SELECT 1');
                } catch (\Throwable $e) {
                    $p->ok(false, '数据库不可达，请先启动 MariaDB：' . $e->getMessage());
                }
                $p->note('站点副本 ' . basename($site->dir()) . ' · 表前缀 ' . $site->prefix());
            });

            (new InstallStage())->run($p, $site, $s);
            (new ContentStage())->run($p, $site, $s);
            (new ThemeStage())->run($p, $site, $s);

            $p->step('收尾：工作区零残留（主配置 / core / 系统层 / 接口 / 本地覆盖层）', function () use ($p, $site): void {
                $res = $site->residue();
                $p->ok($res === [], '工作区指纹被改动：' . implode(', ', $res));
                $p->note('config/app.php · core/ · systems/ · registry.json · config/local.php 全部逐字未动');
            });
        } finally {
            $residue = $site->residue();
            $site->cleanup();
            if (!is_dir($site->dir())) {
                $p->fact('cleanup', 'ok');
            } else {
                $p->fact('cleanup', 'failed');
            }
        }

        $p->fact('passed', $p->passed());
        $p->fact('failed', $p->failed());
        return [
            'lines'   => $p->lines(),
            'passed'  => $p->passed(),
            'failed'  => $p->failed(),
            'residue' => $residue,
            'facts'   => $p->facts(),
        ];
    }
}
