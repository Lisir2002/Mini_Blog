<?php
declare(strict_types=1);
namespace Wx\Pages\Install;

use PDO;
use Throwable;
use Wx\Core\Kernel;

/**
 * 安装执行者 —— 五步标准化流程，每一步只做一件事，失败不写下一步的状态。
 *
 * 与常见 PHP 安装器的结构一致（环境检查 → 数据库 → 管理员 → 迁移 → 加锁），
 * 但落地上有三条自己的约束：
 * 1. 只写 config/local.php，不覆盖带注释的主配置，删掉即回退；
 * 2. 写操作必须带一次性令牌，且仅限本机来源；
 * 3. 建表复用各系统层的 ensureSchema，迁移基线复用 release 层台账，不另造一套。
 */
final class Installer
{
    public function __construct(private Kernel $kernel, private InstallState $state) {}

    /** @return array{ok: bool, checks: list<array{name: string, ok: bool, detail: string}>} */
    public function preflight(): array
    {
        $root = $this->kernel->root();
        $exts = ['pdo_mysql', 'json'];
        $checks = [
            ['name' => 'php', 'ok' => PHP_VERSION_ID >= 80100, 'detail' => PHP_VERSION],
            ['name' => 'extensions', 'ok' => array_filter($exts, static fn(string $e): bool => !extension_loaded($e)) === [],
             'detail' => implode('、', $exts)],
            ['name' => 'config_writable', 'ok' => is_writable($root . '/config'), 'detail' => 'config/'],
            ['name' => 'storage_writable', 'ok' => is_writable($root . '/storage'), 'detail' => 'storage/'],
            ['name' => 'not_installed', 'ok' => !$this->state->installed(), 'detail' => 'storage/install.lock'],
        ];
        $ok = true;
        foreach ($checks as $check) $ok = $ok && (bool) $check['ok'];
        return ['ok' => $ok, 'checks' => $checks];
    }

    /**
     * 测试连接并写入 config/local.php；库不存在时尝试创建一次。
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function database(array $input): array
    {
        $host = (string) ($input['host'] ?? '127.0.0.1');
        $port = (int) ($input['port'] ?? 3306);
        $name = (string) ($input['database'] ?? '');
        $user = (string) ($input['user'] ?? '');
        $pass = (string) ($input['pass'] ?? '');
        $prefix = (string) ($input['prefix'] ?? 'wx_');
        if ($name === '' || $user === '') {
            throw new \RuntimeException('数据库名与用户名必填', 42201);
        }
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $name) !== 1) {
            throw new \RuntimeException('数据库名只允许字母、数字与下划线', 42201);
        }
        if (preg_match('/^[A-Za-z0-9_]{0,16}$/', $prefix) !== 1) {
            throw new \RuntimeException('表前缀只允许字母、数字与下划线', 42201);
        }
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        $created = false;
        try {
            $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (Throwable $e) {
            $server = "mysql:host={$host};port={$port};charset=utf8mb4";
            $pdo = new PDO($server, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $name . '` CHARACTER SET utf8mb4');
            $pdo->exec('USE `' . $name . '`');
            $created = true;
        }
        $pdo->query('SELECT 1');
        $this->writeLocal(['database' => ['dsn' => $dsn, 'user' => $user, 'pass' => $pass, 'prefix' => $prefix]]);
        return ['connected' => true, 'created' => $created, 'version' => (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION)];
    }

    /**
     * 创建或重置管理员账号（委托给只做这件事的协作者）。
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function admin(array $input): array
    {
        return (new InstallerAccount($this->kernel))->create($input);
    }

    /** @return array<string, mixed> 建立迁移基线（建表由各层 ensureSchema 完成） */
    public function migrate(): array
    {
        return (new InstallerMigrate($this->kernel))->run();
    }

    /** @return array<string, mixed> */
    public function finish(): array
    {
        $this->state->markInstalled();
        return ['installed' => true, 'at' => date(DATE_ATOM), 'lock' => 'storage/install.lock'];
    }

    /** @param array<string, mixed> $data */
    private function writeLocal(array $data): void
    {
        $file = $this->kernel->root() . '/config/local.php';
        $existing = is_file($file) ? (require $file) : [];
        $merged = array_replace_recursive(is_array($existing) ? $existing : [], $data);
        $code = "<?php\n// 由安装器写入：只保存本机差异，删除本文件即回到 config/app.php 的默认值。\nreturn "
            . var_export($merged, true) . ";\n";
        if (@file_put_contents($file, $code) === false) {
            throw new \RuntimeException('无法写入 config/local.php', 50001);
        }
        @chmod($file, 0600);
    }
}
