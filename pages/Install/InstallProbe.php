<?php
declare(strict_types=1);
namespace Wx\Pages\Install;

use Throwable;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Contracts\DatabaseInterface;
use Wx\Core\Kernel;
use Wx\Systems\Data\Db;

/**
 * 安装向导的环境探测协作者 —— 只读盘点，不写配置、不建表、不改状态。
 * 建表由各系统层 boot 阶段幂等完成；探测失败时降级为空列表而非中断页面。
 */
final class InstallProbe
{
    public function __construct(private Kernel $kernel) {}

    public function role(): string { return 'install_probe'; }

    /**
     * 安装进度里的两个「库内事实」：管理员是否已建、迁移台账是否已登记。
     * 读不到库或表还没建时返回 false 而不抛错 —— 向导页面在任何阶段都必须能渲染。
     *
     * @return array{admin: bool, migrate: bool}
     */
    public function progress(): array
    {
        $db = Db::optional($this->kernel);
        if ($db === null) return ['admin' => false, 'migrate' => false];
        $prefix = $db->prefix();
        try {
            $admin = ((int) $db->pdo()->query(
                'SELECT COUNT(*) FROM ' . $prefix . "user_accounts WHERE role = 'admin'"
            )->fetchColumn()) > 0;
            $migrate = ((int) $db->pdo()->query(
                'SELECT COUNT(*) FROM ' . $prefix . 'schema_migrations'
            )->fetchColumn()) > 0;
        } catch (Throwable) {
            return ['admin' => false, 'migrate' => false];
        }
        return ['admin' => $admin, 'migrate' => $migrate];
    }

    /** @return list<array{title: string, ok: bool, value: string, note: string}> */
    public function rows(): array
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
        $apiCount = $k->container()->has(ApiRegistryInterface::class)
            ? count($k->container()->resolve(ApiRegistryInterface::class)->endpoints())
            : 0;
        $active = $k->config()->get('theme.active');

        return [
            ['title' => 'PHP 运行时', 'ok' => PHP_VERSION_ID >= 80100, 'value' => PHP_VERSION,
             'note' => '最低版本已定案为 8.1+；当前代码全数原生 PHP'],
            ['title' => 'MySQL 连接', 'ok' => $connected, 'value' => $connected ? 'mysql' : 'fallback',
             'note' => $connected ? 'PDO 已连通，各系统层使用真实 MySQL 实现' : '未连库；已回退内存 / 会话适配器，框架仍可启动'],
            ['title' => '系统层自建表', 'ok' => $tables !== [], 'value' => count($tables) . ' 张',
             'note' => $tables === [] ? '暂无；各系统层 boot 时会幂等建表' : implode('、', $tables)],
            ['title' => '系统层装配', 'ok' => true, 'value' => count($k->systems()->instances()) . ' 层',
             'note' => implode(' → ', $k->systems()->order())],
            ['title' => '规范化接口', 'ok' => $apiCount > 0, 'value' => $apiCount . ' 个',
             'note' => '清单见 api/registry.json；每接口一份操作文档'],
            ['title' => '当前主题', 'ok' => true,
             'value' => $active === null ? '未启用（Headless）' : (string) $active,
             'note' => '未启用主题时全站零样式、零 HTML，属预期默认状态'],
        ];
    }
}
