<?php
declare(strict_types=1);
namespace Wx\Systems\Api;

use Throwable;
use Wx\Contracts\DatabaseInterface;
use Wx\Contracts\RendererInterface;
use Wx\Core\Kernel;
use Wx\Systems\Data\Db;

/**
 * 内核只读盘点协作者 —— 系统信息、健康检查、契约清单、装配顺序、日志查询。
 * 它不注册路由、不分发请求；ApiSystem 只负责把 operation 绑定到它的方法。
 */
final class SystemIntrospection
{
    public function __construct(private Kernel $kernel, private ApiRegistry $registry) {}

    public function role(): string { return 'system_introspection'; }

    /** @return array<string, mixed> */
    public function info(): array
    {
        $k = $this->kernel;
        $v = json_decode((string) @file_get_contents($k->root() . '/core/version.json'), true);
        return [
            'framework'      => 'Mini Blog',
            'core_version'   => (string) ($v['version'] ?? 'unknown'),
            'php'            => PHP_VERSION,
            'requires_php'   => (string) ($v['requires_php'] ?? '>=8.1'),
            'api_version'    => (string) $k->config()->get('api.version', 'v1'),
            'systems'        => count($k->systems()->instances()),
            'contracts'      => count($k->container()->contracts()),
            'api_operations' => count($this->registry->endpoints()),
            'database'       => Db::connected($k) ? 'mysql' : 'fallback',
            'theme_active'   => $k->config()->get('theme.active'),
            'headless'       => !$k->container()->has(RendererInterface::class),
        ];
    }

    /** @return array<string, mixed> */
    /**
     * 健康检查 —— 返回可判定的分级状态，而不是「恒定 ok」。
     *
     * v0.20 前这里第一行就是 'status' => 'ok'：库断了、目录不可写、主题配置指向不存在的
     * 目录，运维看到的仍是绿灯。健康检查的价值全在「能不能据此摘流量 / 判上线」，
     * 恒真等于没有。现在逐项检查，任一 down → down，其余非 ok → degraded。
     * 同时不再回显全表名（public 接口没有理由暴露 schema，且 SHOW TABLES 随表数变慢）。
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        $k = $this->kernel;
        $connected = Db::connected($k);

        $checks = [
            'database' => $connected
                ? ['status' => 'ok',   'detail' => 'mysql']
                : ['status' => 'down', 'detail' => 'fallback'],
            'storage'  => $this->storageCheck($k->root()),
            'theme'    => $this->themeCheck($k->root(), $k->config()->get('theme.active')),
        ];

        $status = 'ok';
        foreach ($checks as $check) {
            if ($check['status'] === 'down') { $status = 'down'; break; }
            if ($check['status'] !== 'ok') $status = 'degraded';
        }

        $tables = [];
        if ($connected) {
            try {
                $tables = $k->container()->resolve(DatabaseInterface::class)->query('SHOW TABLES');
            } catch (Throwable) {
                $tables = [];
            }
        }

        return [
            'status'       => $status,
            'checks'       => $checks,
            'database'     => $connected ? 'mysql' : 'fallback',
            'table_count'  => count($tables),
            'systems'      => count($k->systems()->instances()),
            'theme_active' => $k->config()->get('theme.active'),
        ];
    }

    /** @return array{status: string, detail: string} */
    private function storageCheck(string $root): array
    {
        $dir = $root . '/storage';
        $ok = is_dir($dir) ? is_writable($dir) : @mkdir($dir, 0775, true);
        return [
            'status' => $ok ? 'ok' : 'down',
            'detail' => $ok ? 'writable' : 'not_writable',
        ];
    }

    /** @return array{status: string, detail: string} */
    private function themeCheck(string $root, mixed $active): array
    {
        if ($active === null || $active === '') {
            return ['status' => 'ok', 'detail' => 'headless'];
        }
        $slug = (string) $active;
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) !== 1) {
            return ['status' => 'degraded', 'detail' => 'unresolved:' . $slug];
        }
        // slug 与目录名不必相同（bare ↔ themes/Bare），只读清单判定，不引导主题包。
        foreach (glob($root . '/themes/*/theme.json') ?: [] as $file) {
            $meta = json_decode((string) @file_get_contents($file), true);
            $declared = is_array($meta)
                ? (string) ($meta['slug'] ?? basename(dirname($file)))
                : basename(dirname($file));
            if ($declared === $slug) return ['status' => 'ok', 'detail' => $slug];
        }
        return ['status' => 'degraded', 'detail' => 'unresolved:' . $slug];
    }

    /** @return array<string, mixed> */
    public function contracts(): array
    {
        return ['contracts' => $this->kernel->container()->contracts(),
            'overrides' => $this->kernel->container()->overrides()];
    }

    /** @return array<string, mixed> */
    public function layers(): array
    {
        return ['order' => $this->kernel->systems()->order(),
            'manifests' => array_keys($this->kernel->systems()->manifests())];
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    /**
     * 读取某一天的日志尾部。
     *
     * `total` 是**文件内非空行总数**，不是本次返回行数 —— 后者是 `returned`。
     * 两者必须在同一响应里同时给出：只给一个数时，分页界面无法区分
     * 「这一天只有 20 行」与「有 2000 行，我只看到最后 20 行」。
     * 这曾经是个真实缺陷：先 `array_slice()` 再 `count()`，`total` 永远是 `limit`。
     *
     * `dates` 让页面不必知道日志目录在哪。日志文件按日期平铺在 `storage/logs`，
     * 这是日志系统的实现细节；页面只消费「有哪几天」，不拼路径。
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    public function logs(array $in): array
    {
        $date = (string) ($in['date'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new ApiOperationException('date 格式应为 YYYY-MM-DD', 42201);
        }
        $limit = max(1, min(200, (int) ($in['limit'] ?? 20)));
        $dir   = $this->kernel->root() . '/storage/logs';
        $dates = $this->logDates($dir);
        $file  = $dir . '/' . $date . '.log';

        // 「文件不在」与「文件在但没行」是两种事实：前者说明那天没跑过站点，
        // 后者说明跑了但没写日志。返回 exists 让调用方不必靠猜。
        if (!is_file($file)) {
            return ['date' => $date, 'exists' => false, 'total' => 0, 'returned' => 0,
                'truncated' => false, 'lines' => [], 'dates' => $dates];
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            // 目录可读但文件不可读（权限 / 被占用）是真故障，不能当成空日志。
            throw new ApiOperationException('日志文件不可读：' . $date . '.log', 50001);
        }

        $all   = array_values(array_filter(explode("\n", $raw),
            static fn(string $l): bool => trim($l) !== ''));
        $total = count($all);
        $lines = $limit >= $total ? $all : array_slice($all, -$limit);

        return [
            'date'      => $date,
            'exists'    => true,
            'total'     => $total,
            'returned'  => count($lines),
            'truncated' => $total > count($lines),
            'lines'     => $lines,
            'dates'     => $dates,
        ];
    }

    /**
     * 可查询的日期，倒序，最多 60 天。
     *
     * 只认 `YYYY-MM-DD.log`：目录里别的东西（.gitkeep、归档子目录）不该出现在日期选择里。
     *
     * @return list<string>
     */
    private function logDates(string $dir): array
    {
        $out = [];
        foreach (glob($dir . '/*.log') ?: [] as $file) {
            $name = basename($file, '.log');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $name) === 1) $out[] = $name;
        }
        rsort($out, SORT_STRING);
        return array_slice($out, 0, 60);
    }
}
