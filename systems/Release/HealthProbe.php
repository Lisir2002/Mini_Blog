<?php
declare(strict_types=1);
namespace Wx\Systems\Release;

use Throwable;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Contracts\DatabaseInterface;
use Wx\Contracts\LoggerInterface;
use Wx\Core\Kernel;

/**
 * 升级后的健康门禁 —— 只回答「站点现在还活着吗」，不改任何状态。
 *
 * 为什么必须有：文件替换成功不等于升级成功。表缺了、契约解析不了、
 * 系统层顺序断了，都会在下一个请求才爆，而那时已经无法判断是哪次升级造成的。
 */
final class HealthProbe
{
    public function __construct(private Kernel $kernel) {}

    public function role(): string { return 'health_probe'; }

    /** @return array{ok: bool, checks: list<array{name: string, ok: bool, detail: string}>} */
    public function run(): array
    {
        $checks = [
            $this->check('database', fn(): string => $this->database()),
            $this->check('tables', fn(): string => $this->tables()),
            $this->check('contracts', fn(): string => $this->contracts()),
            $this->check('systems', fn(): string => $this->systems()),
            $this->check('operations', fn(): string => $this->operations()),
        ];
        $ok = true;
        foreach ($checks as $check) $ok = $ok && $check['ok'];
        return ['ok' => $ok, 'checks' => $checks];
    }

    /** @param callable(): string $probe */
    private function check(string $name, callable $probe): array
    {
        try {
            return ['name' => $name, 'ok' => true, 'detail' => $probe()];
        } catch (Throwable $e) {
            return ['name' => $name, 'ok' => false, 'detail' => $e->getMessage()];
        }
    }

    private function database(): string
    {
        if (!$this->kernel->container()->has(DatabaseInterface::class)) {
            return '未绑定数据库实现（内存回退）';
        }
        $this->kernel->container()->resolve(DatabaseInterface::class)->query('SELECT 1');
        return 'PDO 可用';
    }

    private function tables(): string
    {
        if (!$this->kernel->container()->has(DatabaseInterface::class)) {
            return '跳过：无数据库连接';
        }
        $db = $this->kernel->container()->resolve(DatabaseInterface::class);
        $rows = $db->query('SHOW TABLES');
        $tables = array_map(static fn(array $row): string => (string) reset($row), $rows);
        $required = $db->prefix() . 'content_posts';
        if ($tables !== [] && !in_array($required, $tables, true)) {
            throw new ReleaseException('关键表缺失：' . $required, 50003);
        }
        return count($tables) . ' 张';
    }

    private function contracts(): string
    {
        $resolved = [];
        foreach ([LoggerInterface::class, DatabaseInterface::class] as $contract) {
            if ($this->kernel->container()->has($contract)) {
                $this->kernel->container()->resolve($contract);
                $resolved[] = substr((string) strrchr($contract, '\\'), 1);
            }
        }
        if ($resolved === []) throw new ReleaseException('核心契约无法解析', 50003);
        return implode('、', $resolved);
    }

    private function systems(): string
    {
        $order = $this->kernel->systems()->order();
        if ($order === []) throw new ReleaseException('系统层装配为空', 50003);
        foreach (['lifecycle', 'release'] as $required) {
            if (!in_array($required, $order, true)) {
                throw new ReleaseException('缺少系统层：' . $required, 50003);
            }
        }
        return count($order) . ' 层';
    }

    private function operations(): string
    {
        if (!$this->kernel->container()->has(ApiRegistryInterface::class)) {
            return '跳过：接口系统未启用';
        }
        $count = count($this->kernel->container()->resolve(ApiRegistryInterface::class)->endpoints());
        if ($count === 0) throw new ReleaseException('接口清单为空', 50003);
        return $count . ' 个';
    }
}
