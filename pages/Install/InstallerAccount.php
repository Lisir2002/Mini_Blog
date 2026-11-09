<?php
declare(strict_types=1);
namespace Wx\Pages\Install;

use RuntimeException;
use Wx\Contracts\DatabaseInterface;
use Wx\Core\Kernel;
use Wx\Systems\Database\Db;

/** 安装步骤 · 管理员账号：只做「创建一个可登录的 admin」，不碰配置与建表。 */
final class InstallerAccount
{
    public function __construct(private Kernel $kernel) {}

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $username = trim((string) ($input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        if (preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username) !== 1) {
            throw new RuntimeException('用户名需 3–64 位字母、数字、点、下划线或连字符', 42201);
        }
        if (strlen($password) < 8) {
            throw new RuntimeException('管理员密码至少 8 位', 42201);
        }
        $db = $this->db();
        $stmt = $db->pdo()->prepare(
            'INSERT INTO ' . $db->prefix() . 'user_accounts (username,password_hash,display_name,role,created_at)'
            . ' VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = VALUES(role)'
        );
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $username, 'admin', date('Y-m-d H:i:s')]);
        return ['username' => $username, 'role' => 'admin'];
    }

    private function db(): DatabaseInterface
    {
        $db = Db::optional($this->kernel);
        if ($db === null) {
            throw new RuntimeException('数据库未连接：请先完成 database 步骤并确认 config/local.php', 50004);
        }
        return $db;
    }
}
