<?php
declare(strict_types=1);
namespace Wx\Systems\Api;

use Throwable;
use Wx\Contracts\DatabaseInterface;
use Wx\Contracts\RendererInterface;
use Wx\Core\Kernel;
use Wx\Systems\Database\Db;

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
    public function health(): array
    {
        $k = $this->kernel;
        $connected = Db::connected($k);
        $tables = [];
        if ($connected) {
            try {
                $tables = array_map(
                    static fn(array $row): string => (string) reset($row),
                    $k->container()->resolve(DatabaseInterface::class)->query('SHOW TABLES')
                );
            } catch (Throwable) {
                $tables = [];
            }
        }
        return [
            'status'       => 'ok',
            'database'     => $connected ? 'mysql' : 'fallback',
            'tables'       => $tables,
            'systems'      => count($k->systems()->instances()),
            'theme_active' => $k->config()->get('theme.active'),
        ];
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
    public function logs(array $in): array
    {
        $date = (string) ($in['date'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new ApiOperationException('date 格式应为 YYYY-MM-DD', 42201);
        }
        $limit = max(1, min(200, (int) ($in['limit'] ?? 20)));
        $file  = $this->kernel->root() . '/storage/logs/' . $date . '.log';
        if (!is_file($file)) return ['date' => $date, 'lines' => [], 'total' => 0];
        $lines = array_filter(explode("\n", (string) file_get_contents($file)),
            static fn(string $l): bool => trim($l) !== '');
        $lines = array_slice(array_values($lines), -$limit);
        return ['date' => $date, 'lines' => $lines, 'total' => count($lines)];
    }
}
