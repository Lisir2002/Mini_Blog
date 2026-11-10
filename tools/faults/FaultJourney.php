<?php
declare(strict_types=1);
namespace Wx\Tools\Faults;

use Wx\Tools\Journey\InstallStage;
use Wx\Tools\Journey\JourneyState;
use Wx\Tools\Journey\Probe;
use Wx\Tools\Journey\Site;

/**
 * 故障旅程编排 —— 与 `tools/journey.php` 互补：那条跑「顺利时能不能用」，这条跑「坏的时候会不会骗人」。
 *
 * 为什么值得单独一条旅程：成功路径的守卫只能证明「零件都对」，而生产事故几乎都发生在
 * 依赖缺失、凭据过期、并发碰撞、容量越界这四类边界上。这类问题的共同形态不是崩溃，
 * 而是「安静地错」——返回 200 空文件、健康检查恒绿、失败后占位不释放。
 *
 * 复用 Journey 的 Site / Probe：隔离站点、真实请求管线、零残留指纹是同一套基础设施，
 * 这里不复制第二份。前置走真实的五步安装，故障注入在「装好的站」上做。
 */
final class FaultJourney
{
    /** @return array{lines:list<string>, passed:int, failed:int, residue:list<string>, facts:array} */
    public static function run(string $root): array
    {
        $site = new Site($root);
        $p = new Probe();
        $s = new JourneyState();
        $residue = [];

        try {
            $p->step('环境：数据库可达（故障旅程同样要求真实 MariaDB）', function () use ($p, $site): void {
                $cfg = require $site->root() . '/config/app.php';
                $db = (array) ($cfg['database'] ?? []);
                try {
                    $pdo = new \PDO((string) ($db['dsn'] ?? ''), (string) ($db['user'] ?? ''), (string) ($db['pass'] ?? ''));
                    $pdo->query('SELECT 1');
                } catch (\Throwable $e) {
                    $p->ok(false, '数据库不可达，请先启动 MariaDB：' . $e->getMessage());
                }
                $p->note('站点副本 ' . basename($site->dir()) . ' · 表前缀 ' . $site->prefix());
            });

            // 故障必须注入在「真实装好的站」上：未安装的站点会掩盖掉一半问题。
            (new InstallStage())->run($p, $site, $s);

            $p->step('前置：用安装的管理员账号签发真实令牌', function () use ($p, $site, $s): void {
                $live = $site->fresh();
                $d = $p->data($p->post($live, '/api/v1/auth/token', [
                    'username' => 'webmaster', 'password' => 'Journey-Pass-2026',
                ]), 'auth.token');
                $s->bearer = (string) ($d['token'] ?? '');
                $p->ok(preg_match('/^[0-9a-f]{64}$/', $s->bearer) === 1, '未取得 64 位令牌');
                $p->note('令牌已签发给 webmaster（故障注入前先证明身份链路是通的）');
            });

            (new DataFaults())->run($p, $site, $s);
            (new AuthFaults())->run($p, $site, $s);
            (new RequestFaults())->run($p, $site, $s);
            // 启动期故障必须最后跑：它会替换 storage 与 local.php，且在真实 SAPI 下验证。
            (new StartupFaults())->run($p, $site);

            $p->step('收尾：工作区零残留（故障注入不得改动真实仓库）', function () use ($p, $site): void {
                $res = $site->residue();
                $p->ok($res === [], '工作区指纹被改动：' . implode(', ', $res));
                $p->note('config/app.php · core/ · systems/ · registry.json · config/local.php 全部逐字未动');
            });
        } finally {
            $residue = $site->residue();
            $site->cleanup();
            $p->fact('cleanup', is_dir($site->dir()) ? 'failed' : 'ok');
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
