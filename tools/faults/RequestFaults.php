<?php
declare(strict_types=1);
namespace Wx\Tools\Faults;

use Wx\Tools\Journey\JourneyState;
use Wx\Tools\Journey\Probe;
use Wx\Tools\Journey\Site;

/**
 * 请求与呈现面故障 —— 上传越界、主题配置指向不存在的包。
 *
 * 越界的正确反应是「拒绝并且什么都不留下」：一旦先落盘再校验，磁盘会被
 * 失败的请求慢慢填满，而每个失败的请求都觉得自己没成功。
 *
 * 主题指向坏 slug 时，站点必须继续输出纯 JSON —— Headless 是框架的地板，
 * 不是「有主题时才成立」的承诺。健康检查同时要报 degraded，让运维知道
 * 「能服务，但呈现层没接上」。
 */
final class RequestFaults
{
    public function run(Probe $p, Site $site, JourneyState $s): void
    {
        $auth = ['Authorization' => 'Bearer ' . $s->bearer];

        $p->step('上传超限：42201 拒绝，且不落盘', function () use ($p, $site, $auth): void {
            $k = $site->kernel(['attachment' => ['max_bytes' => 512]]);
            $dir = $site->dir() . '/storage/attachments';
            $before = $this->countFiles($dir);

            // 合法的 PNG 魔数 + 2KB 填充：类型校验会放过，只有大小该拦住它。
            $big = "\x89PNG\r\n\x1a\n" . str_repeat('A', 2048);
            $res = $p->post($k, '/attach/upload', $big, ['name' => 'big.png'], $auth);
            $p->ok($res['status'] === 422, '超限未被拒绝，实际 HTTP ' . $res['status']);
            $p->ok((int) ($res['json']['code'] ?? 0) === 42201,
                '错误码应为 42201，实际 ' . ($res['json']['code'] ?? '?'));
            $p->ok($this->countFiles($dir) === $before, '被拒绝的上传仍然写了文件');

            $p->note('max_bytes=512 · 上传 2KB · 422/42201 · 文件数 ' . $before . ' → ' . $this->countFiles($dir));
        });

        $p->step('重复 slug：40901 状态冲突，不能报成 50001', function () use ($p, $site, $auth): void {
            $live = $site->fresh();
            $payload = ['title' => 'dup probe', 'slug' => 'fault-dup', 'body' => 'x'];
            $first = $p->post($live, '/api/v1/content/posts', $payload, [], $auth);
            $p->ok($first['status'] === 200, '首次创建失败：' . substr($first['body'], 0, 160));

            $again = $p->post($site->fresh(), '/api/v1/content/posts', $payload, [], $auth);
            $p->ok($again['status'] === 409, '重复 slug 应返回 409，实际 ' . $again['status']);
            $p->ok((int) ($again['json']['code'] ?? 0) === 40901,
                '错误码应为 40901（状态冲突），实际 ' . ($again['json']['code'] ?? '?')
                . '（50001 意味着把客户端输入问题报成了服务端故障）');
            $p->note('同 slug 二次创建 → 409/40901 · 唯一索引冲突不再冒充 50001');
        });

        $p->step('主题指向不存在的包：degraded，站点仍输出纯 JSON', function () use ($p, $site): void {
            $k = $site->kernel(['theme' => ['active' => 'no-such-theme']]);

            $h = $p->data($p->get($k, '/api/v1/system/health'), 'health');
            $p->ok(($h['status'] ?? '') === 'degraded', '应为 degraded，实际 ' . ($h['status'] ?? '?'));
            $p->ok(str_starts_with((string) ($h['checks']['theme']['detail'] ?? ''), 'unresolved:'),
                'theme 项未说明具体无法解析的 slug');

            $home = $p->get($k, '/');
            $p->ok($home['status'] === 200, '主题缺失不应让首页 500，实际 ' . $home['status']);
            $p->ok(str_contains($home['ct'], 'json'), '主题缺失时应退化为纯 JSON，实际 ' . $home['ct']);
            $p->ok(!str_contains($home['body'], '<div'), '核心不应输出标签兜底');
            $p->note('theme.active=no-such-theme · health=degraded/unresolved · 首页 200 纯 JSON');
        });
    }

    private function countFiles(string $dir): int
    {
        if (!is_dir($dir)) return 0;
        $n = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile()) $n++;
        }
        return $n;
    }
}
