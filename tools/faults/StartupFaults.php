<?php
declare(strict_types=1);
namespace Wx\Tools\Faults;

use Wx\Tools\Journey\Probe;
use Wx\Tools\Journey\Server;
use Wx\Tools\Journey\Site;

/**
 * 启动期故障 —— 唯一需要「真实 SAPI」才能证明的一段。
 *
 * `Kernel::handle()` 绕过了 `run()` 的异常边界，所以配置损坏这类构造期故障
 * 在探针里根本看不到最终形态。这里用 PHP 内置服务器起一个与生产同构的站点，
 * 故意打开 display_errors：路径与堆栈一旦泄漏，断言就会失败。
 *
 * 两种故障必须区分：
 *   · 装配期（storage 不可用）→ 异常发生在 run() 内，边界生效；
 *   · 构造期（config/local.php 损坏）→ 异常发生在 run() 之前，只有 serve() 能兜住。
 */
final class StartupFaults
{
    private const BARE = "500 Internal Server Error\n";

    public function run(Probe $p, Site $site): void
    {
        $dir = $site->dir();
        $server = Server::start($dir . '/public', true);

        try {
            $p->step('基线：真实 SAPI 下的首页 200（对照组先成立，才谈得上故障）', function () use ($p, $server): void {
                $r = $server->get('/');
                $p->ok($r['status'] === 200, '基线首页应为 200，实际 ' . $r['status']);
                $p->ok($r['body'] !== '', '基线首页为空');
                $p->note('HTTP ' . $r['status'] . ' · ' . ($r['headers']['content-type'] ?? '?'));
            });

            $p->step('装配期故障：storage 变成普通文件 → 500 纯文本，不是白屏', function () use ($p, $server, $dir): void {
                rename($dir . '/storage', $dir . '/storage-bak');
                file_put_contents($dir . '/storage', 'not a directory');

                $r = $server->get('/');
                $this->assertBareError($p, $r, '装配期故障');
                $p->note('异常发生在内核装配阶段，run() 边界接住：' . strlen($r['body']) . ' 字节');
            });

            $p->step('装配期自愈：目录还原后同进程 200，无需重启', function () use ($p, $server, $dir): void {
                @unlink($dir . '/storage');
                rename($dir . '/storage-bak', $dir . '/storage');

                $r = $server->get('/');
                $p->ok($r['status'] === 200, '修复后仍为 ' . $r['status'] . '：故障被固化了');
                $p->ok($r['body'] !== '', '修复后响应为空');
                $p->note('故障不粘滞：同一服务器进程内即时恢复');
            });

            $p->step('构造期故障：config/local.php 截断 → 500 纯文本，不泄露路径与堆栈', function () use ($p, $server, $site): void {
                $file = $site->dir() . '/config/local.php';
                $original = (string) file_get_contents($file);
                $p->ok($original !== '', '装好的站点没有 local.php，本场景无意义');
                file_put_contents($file, "<?php\nreturn ['theme' => ['active' => ");

                try {
                    $r = $server->get('/');
                } finally {
                    file_put_contents($file, $original);
                }

                $this->assertBareError($p, $r, '构造期故障');
                $p->ok(!str_contains($r['body'], $site->dir()), '响应体泄露了站点绝对路径');
                $p->ok(stripos($r['body'], 'parse error') === false, '响应体泄露了 ParseError');
                $p->ok(stripos($r['body'], 'stack trace') === false, '响应体泄露了堆栈');
                $p->ok(str_contains($server->log(), 'wx: unhandled:'), 'SAPI 日志没有记录故障，排障将无从下手');
                $p->note('display_errors=1 下仍为 26 字节纯文本；故障进 SAPI 日志，不进响应体');
            });

            $p->step('构造期自愈：local.php 还原后 200', function () use ($p, $server): void {
                $r = $server->get('/');
                $p->ok($r['status'] === 200, '还原配置后仍为 ' . $r['status']);
                $p->note('配置损坏不是永久性损坏：恢复文件即恢复服务');
            });
        } finally {
            $server->stop();
        }
    }

    /** 统一的「兜底形态」断言：状态、介质类型、正文逐字、零标签。 */
    private function assertBareError(Probe $p, array $r, string $where): void
    {
        $p->ok($r['status'] === 500, $where . ' 应返回 500，实际 ' . $r['status']);
        $p->ok($r['body'] === self::BARE,
            $where . ' 正文不是标准兜底（' . strlen($r['body']) . ' 字节）：' . substr($r['body'], 0, 160));
        $p->ok(str_starts_with((string) ($r['headers']['content-type'] ?? ''), 'text/plain'),
            $where . ' 介质类型应为 text/plain，实际 ' . ($r['headers']['content-type'] ?? '缺失'));
        $p->ok(!str_contains($r['body'], '<'), $where . ' 响应体出现标签');
    }
}
