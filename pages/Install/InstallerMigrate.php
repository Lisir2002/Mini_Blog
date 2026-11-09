<?php
declare(strict_types=1);
namespace Wx\Pages\Install;

use RuntimeException;
use Wx\Core\Kernel;
use Wx\Systems\Database\Db;
use Wx\Systems\Release\MigrationRunner;
use Wx\Systems\Release\PdoMigrationStore;

/**
 * 安装步骤 · 建表与迁移基线。
 *
 * 建表不在安装器里重写一遍：各系统层 boot 时的 ensureSchema 已经幂等建表；
 * 这里只负责在「全新站点」建立迁移基线，避免第一次升级时重放建站以来的所有脚本。
 */
final class InstallerMigrate
{
    public function __construct(private Kernel $kernel) {}

    /** @return array<string, mixed> */
    public function run(): array
    {
        $db = Db::optional($this->kernel);
        if ($db === null) {
            throw new RuntimeException('数据库未连接：请先完成 database 步骤', 50004);
        }
        $store = new PdoMigrationStore($db);
        $runner = new MigrationRunner($this->kernel->root(), $db, $store, $this->kernel->hooks());
        $registered = $store->all() === [] ? $runner->baseline() : 0;
        $prefix = $db->prefix();
        $tables = array_filter(
            array_map(static fn(array $row): string => (string) reset($row), $db->query('SHOW TABLES')),
            static fn(string $name): bool => str_starts_with($name, $prefix)
        );
        return ['tables' => count($tables), 'baseline' => $registered, 'applied' => count($store->all())];
    }
}
