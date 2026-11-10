<?php
declare(strict_types=1);
namespace Wx\Tools\Journey;

use Wx\Core\Kernel;
use Wx\Systems\Lifecycle\Fs;

/**
 * 隔离站点 —— 全链路旅程只在 /tmp 的副本里落地，绝不碰工作区。
 *
 * 与 tests/fixtures.php 的 tempSite() 同思路，但工具不依赖测试夹具：
 * 复制运行所需目录、用独立表前缀、finally 里删表删目录，
 * 并对工作区做前后指纹比对，把「零残留」变成可断言的事实。
 */
final class Site
{
    private string $dir;
    private string $prefix;
    private string $dsn;
    private string $user;
    private string $pass;
    /** @var array<string, string> */
    private array $fingerprint = [];

    public function __construct(private string $root)
    {
        $cfg = require $root . '/config/app.php';
        $db = (array) ($cfg['database'] ?? []);
        $this->dsn = (string) ($db['dsn'] ?? '');
        $this->user = (string) ($db['user'] ?? '');
        $this->pass = (string) ($db['pass'] ?? '');
        $this->prefix = 'wxj_' . bin2hex(random_bytes(3)) . '_';
        $this->dir = sys_get_temp_dir() . '/wx-journey-' . bin2hex(random_bytes(4));

        $this->fingerprint = $this->measure();
        mkdir($this->dir . '/storage', 0775, true);
        // public/ 一并复制：真实 SAPI 服务器需要与生产同构的文档根与入口文件。
        foreach (['core', 'systems', 'api', 'config', 'themes', 'pages', 'plugins', 'public'] as $part) {
            Fs::copyDir($root . '/' . $part, $this->dir . '/' . $part);
        }
        // 副本必须是「全新站点」：工作区若残留本地状态，不能带进旅程。
        foreach (['/config/local.php', '/storage/install.lock', '/storage/install.token'] as $stale) {
            @unlink($this->dir . $stale);
        }
        $this->dropTables();
    }

    public function dir(): string { return $this->dir; }
    public function prefix(): string { return $this->prefix; }
    public function root(): string { return $this->root; }

    /** 安装期内核：显式指定独立库前缀，并关闭限流与升级门槛。 */
    public function kernel(array $extra = []): Kernel
    {
        return new Kernel($this->dir, array_replace_recursive([
            'theme' => ['active' => null],
            'database' => ['dsn' => $this->dsn, 'user' => $this->user,
                           'pass' => $this->pass, 'prefix' => $this->prefix],
            'api' => ['rate_limit' => ['enabled' => false]],
            'lifecycle' => ['allow_core_update' => true],
            'release' => ['allow_core_update' => true],
        ], $extra));
    }

    /**
     * 装完之后的「真实部署形态」内核：不传任何 database / theme 覆盖，
     * 一切只读副本里 config/local.php。主题切换的 next_request 语义靠它验证。
     */
    public function fresh(): Kernel
    {
        return new Kernel($this->dir, ['api' => ['rate_limit' => ['enabled' => false]]]);
    }

    /** 安装向导提交数据库步骤所需的连接参数（沿用主站库，只换前缀）。 */
    public function databaseInput(): array
    {
        $out = ['host' => '127.0.0.1', 'port' => 3306, 'database' => '',
                'user' => $this->user, 'pass' => $this->pass, 'prefix' => $this->prefix];
        foreach (explode(';', substr($this->dsn, (int) strpos($this->dsn, ':') + 1)) as $part) {
            $pair = explode('=', $part, 2);
            if (count($pair) !== 2) continue;
            $key = trim($pair[0]);
            if ($key === 'host') $out['host'] = $pair[1];
            if ($key === 'port') $out['port'] = (int) $pair[1];
            if ($key === 'dbname') $out['database'] = $pair[1];
        }
        return $out;
    }

    public function localPhp(): string { return (string) @file_get_contents($this->dir . '/config/local.php'); }
    public function appPhpHash(): string { return (string) md5_file($this->dir . '/config/app.php'); }
    public function hasLock(): bool { return is_file($this->dir . '/storage/install.lock'); }

    /** @return list<string> 与开工前不一致的指纹项；空数组 = 工作区零残留 */
    public function residue(): array
    {
        $bad = [];
        foreach ($this->fingerprint as $key => $before) {
            if (($this->measure()[$key] ?? null) !== $before) $bad[] = $key;
        }
        return $bad;
    }

    public function cleanup(): void
    {
        $this->dropTables();
        Fs::removeDir($this->dir);
    }

    /** @return array<string, string> */
    private function measure(): array
    {
        $core = count(glob($this->root . '/core/*.php') ?: [])
              + count(glob($this->root . '/core/*/*.php') ?: []);
        $local = $this->root . '/config/local.php';
        $reg = json_decode((string) @file_get_contents($this->root . '/api/registry.json'), true);
        return [
            'config/app.php'      => (string) md5_file($this->root . '/config/app.php'),
            'config/local.php'    => is_file($local) ? (string) md5_file($local) : 'absent',
            'core_php_files'      => (string) $core,
            'system_layers'       => (string) count(glob($this->root . '/systems/*/system.json') ?: []),
            'api_operations'      => (string) count((array) ($reg['endpoints'] ?? [])),
            'guard_groups'        => (string) count(glob($this->root . '/tests/groups/*.php') ?: []),
        ];
    }

    private function dropTables(): void
    {
        if ($this->dsn === '') return;
        try {
            $pdo = new \PDO($this->dsn, $this->user, $this->pass);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $table) {
                if (str_starts_with((string) $table, $this->prefix)) {
                    $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
                }
            }
        } catch (\Throwable) {
            // 清理失败不应把「旅程结果」伪装成通过：留给 residue() 与上层退出码裁决。
        }
    }
}
