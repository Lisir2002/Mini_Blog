<?php
declare(strict_types=1);
namespace Wx\Tools\Faults;

use Wx\Tools\Journey\JourneyState;
use Wx\Tools\Journey\Probe;
use Wx\Tools\Journey\Site;

/**
 * 数据面故障 —— 数据库不可达、附件字节在其生命周期内消失。
 *
 * 这两类的共同点是「服务端自己知道出了问题」，问题只在于说不说。
 * 库断时如果健康检查还报 ok，运维就无法摘流量；字节丢了如果返回 200 空体，
 * 客户端会把它当成一张 0 字节的图片缓存下来，错得很安静。
 */
final class DataFaults
{
    public function run(Probe $p, Site $site, JourneyState $s): void
    {
        $p->step('数据库不可达：健康检查报 down，不谎报绿灯', function () use ($p, $site): void {
            $in = $site->databaseInput();
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=wx_fault_missing_%s;charset=utf8mb4',
                $in['host'], $in['port'], bin2hex(random_bytes(4)));
            $k = $site->kernel(['database' => ['dsn' => $dsn]]);

            $r = $p->get($k, '/api/v1/system/health');
            $p->ok($r['status'] === 200, '库不可达时健康检查不应崩溃，实际 HTTP ' . $r['status']);
            $d = $p->data($r, 'health');
            $p->ok(($d['status'] ?? '') === 'down', '库不可达仍报 ' . ($d['status'] ?? '?'));
            $p->ok(($d['checks']['database']['status'] ?? '') === 'down', 'database 项未报 down');
            $p->ok(($d['database'] ?? '') === 'fallback', '未标记已回退到内存适配器');
            $p->note('dsn → 不存在的库 · health=down · checks.database=down/fallback · 仍是 200 信封');
        });

        $p->step('数据库恢复：同一份配置回切后自愈，无需重启', function () use ($p, $site): void {
            $k = $site->kernel();
            $d = $p->data($p->get($k, '/api/v1/system/health'), 'health');
            $p->ok(($d['status'] ?? '') === 'ok', '恢复后仍是 ' . ($d['status'] ?? '?'));
            $p->ok((int) ($d['table_count'] ?? 0) > 0, '恢复后读不到表');
            $p->note('回到正常 DSN · health=ok · table_count=' . ($d['table_count'] ?? 0));
        });

        $p->step('附件字节被删：下载返回 50001，而不是 200 空文件', function () use ($p, $site, $s): void {
            $live = $site->fresh();
            $auth = ['Authorization' => 'Bearer ' . $s->bearer];
            $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

            $up = $p->data($p->post($live, '/attach/upload', $png, ['name' => 'lost.png'], $auth), 'attachment.upload');
            $sha = (string) ($up['sha256'] ?? '');
            $p->ok(strlen($sha) === 64, '上传未返回 sha256');
            $file = $site->dir() . '/storage/attachments/'
                  . substr($sha, 0, 2) . '/' . substr($sha, 2, 2) . '/' . $sha;
            $p->ok(is_file($file), '上传未按内容寻址落盘');

            @unlink($file);
            $down = $p->get($site->fresh(), '/attach/download', ['id' => $up['id']], $auth);
            $p->ok($down['status'] === 500, '字节缺失应返回 500，实际 ' . $down['status']);
            $p->ok((int) ($down['json']['code'] ?? 0) === 50001,
                '错误码应为 50001，实际 ' . ($down['json']['code'] ?? '?'));
            $p->ok($down['body'] !== '', '不应输出 200 空体');
            $p->note('删掉内容寻址文件后：HTTP 500 · code=50001 · 明确报「附件字节缺失」');
        });
    }
}
