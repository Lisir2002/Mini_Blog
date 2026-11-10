<?php
declare(strict_types=1);
namespace Wx\Systems\User;

use Wx\Contracts\DatabaseInterface;
use Wx\Contracts\UserProviderInterface;
use Wx\Systems\Data\Schema;

/**
 * 用户系统的 MySQL / PDO 实现。
 * 与 SessionUserProvider 实现同一契约：换实现只需改一行绑定，主题、插件、核心零改动。
 *
 * 登录判定集中在这里（attemptLogin），而不是散在接口执行者里：
 * 「口令错」「账号禁用」「临时锁定」三种失败必须由同一处产生，
 * 否则 API 登录与页面登录会给出不一致的结论，或者一处有锁定、另一处没有。
 */
final class PdoUserProvider implements UserProviderInterface
{
    public const LOGIN_OK       = 'ok';
    public const LOGIN_INVALID  = 'invalid';
    public const LOGIN_DISABLED = 'disabled';
    public const LOGIN_LOCKED   = 'locked';

    /**
     * 账号不存在时用来消耗与真实校验相当的时间，避免「响应快 = 用户不存在」的枚举信号。
     * 这是 bcrypt hash 的固定常量，不参与任何账号，仅用于 password_verify 的空转。
     */
    private const DUMMY_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    public function __construct(private DatabaseInterface $db) {}

    public function ensureSchema(): void { Schema::apply($this->db, __DIR__ . '/migrations'); }

    /** 演示账号仅用于本地原型；投产前必须删除或修改口令。 */
    public function seed(): void
    {
        $stmt = $this->db->pdo()->query('SELECT COUNT(*) FROM ' . $this->table());
        if ((int) $stmt->fetchColumn() > 0) return;
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO ' . $this->table() . ' (username,password_hash,display_name,role,status,created_at,updated_at)
             VALUES (:username,:hash,:display,:role,:status,:created_at,:updated_at)'
        );
        $insert->execute([
            ':username' => 'admin',
            ':hash' => password_hash('admin123', PASSWORD_DEFAULT),
            ':display' => '站点管理员',
            ':role' => 'admin',
            ':status' => 'active',
            ':created_at' => date('Y-m-d H:i:s'),
            ':updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** API 场景下身份由令牌注入，会话上下文不再作为唯一来源。 */
    public function current(): ?array { return null; }

    public function find(int|string $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id,username,display_name,role,status,created_at,last_login_at FROM ' . $this->table() . ' WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => (int) $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** 按用户名查公开资料（user.lookup 钩子的实现细节；不返回口令摘要）。 */
    public function findByUsername(string $username): ?array
    {
        if ($username === '') return null;
        $stmt = $this->db->pdo()->prepare(
            'SELECT id,username,display_name,role,status,created_at,last_login_at FROM ' . $this->table()
            . ' WHERE username = :username LIMIT 1'
        );
        $stmt->execute([':username' => $username]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * 登录判定的唯一入口。
     *
     * 顺序是刻意设计的：先看锁定（不再验证口令，避免锁定期被用来试探），
     * 再验证口令（账号不存在也走同一条 bcrypt 路径），最后才看账号状态。
     * 反过来会让「已禁用」变成一个不需要口令就能探测到的状态。
     *
     * @return array{ok: bool, reason: string, retry_after?: int, user?: array<string, mixed>}
     */
    public function attemptLogin(string $username, string $password, int $maxAttempts = 5, int $lockSeconds = 900): array
    {
        $row = $this->credentialRow($username);
        if ($row === null) {
            password_verify($password, self::DUMMY_HASH);   // 空转，抹平时序差异
            return ['ok' => false, 'reason' => self::LOGIN_INVALID];
        }

        $lockedUntil = $row['locked_until'] === null ? 0 : (int) strtotime((string) $row['locked_until']);
        if ($lockedUntil > time()) {
            return ['ok' => false, 'reason' => self::LOGIN_LOCKED, 'retry_after' => $lockedUntil - time()];
        }
        // 锁定已到期：计数归零，让这次尝试是「干净的一次」，而不是一错就再锁。
        if ($lockedUntil > 0) $this->resetFailures((int) $row['id']);

        if (!password_verify($password, (string) $row['password_hash'])) {
            $this->recordFailure((int) $row['id'], $maxAttempts, $lockSeconds);
            return ['ok' => false, 'reason' => self::LOGIN_INVALID];
        }

        if ((string) $row['status'] !== 'active') {
            return ['ok' => false, 'reason' => self::LOGIN_DISABLED];
        }

        $this->markLogin((int) $row['id']);
        return ['ok' => true, 'reason' => self::LOGIN_OK, 'user' => $this->publicRow($row)];
    }

    /** 兼容旧调用方：只要「能不能登录」的布尔结论。失败原因请用 attemptLogin。 */
    public function verifyCredentials(string $username, string $password): ?array
    {
        $result = $this->attemptLogin($username, $password);
        return $result['ok'] ? $result['user'] : null;
    }

    /** @return array<string, mixed>|null 含口令摘要，仅供 attemptLogin 内部使用 */
    private function credentialRow(string $username): ?array
    {
        if ($username === '') return null;
        $stmt = $this->db->pdo()->prepare(
            'SELECT id,username,display_name,role,status,password_hash,failed_attempts,locked_until,created_at'
            . ' FROM ' . $this->table() . ' WHERE username = :username LIMIT 1'
        );
        $stmt->execute([':username' => $username]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function publicRow(array $row): array
    {
        unset($row['password_hash'], $row['failed_attempts'], $row['locked_until']);
        $row['id'] = (int) $row['id'];
        return $row;
    }

    private function recordFailure(int $id, int $maxAttempts, int $lockSeconds): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->pdo()->prepare(
            'UPDATE ' . $this->table() . ' SET failed_attempts = failed_attempts + 1, updated_at = :now WHERE id = :id'
        )->execute([':now' => $now, ':id' => $id]);

        $stmt = $this->db->pdo()->prepare('SELECT failed_attempts FROM ' . $this->table() . ' WHERE id = :id');
        $stmt->execute([':id' => $id]);
        if ((int) $stmt->fetchColumn() < max(1, $maxAttempts)) return;

        // 达到阈值：锁定 + 清零。清零让「锁定到期」成为一次全新的机会窗口，
        // 而不是让用户在解锁后第一次手滑就又锁十五分钟。
        $until = date('Y-m-d H:i:s', time() + max(60, $lockSeconds));
        $this->db->pdo()->prepare(
            'UPDATE ' . $this->table() . ' SET failed_attempts = 0, locked_until = :until, updated_at = :now WHERE id = :id'
        )->execute([':until' => $until, ':now' => $now, ':id' => $id]);
    }

    /** 登录成功：清失败计数、解除锁定、记录最后登录时间。 */
    private function markLogin(int $id): void
    {
        // 原生预处理不允许同一命名占位符出现两次（:now 复用会直接抛 HY093）。
        $now = date('Y-m-d H:i:s');
        $this->db->pdo()->prepare(
            'UPDATE ' . $this->table() . ' SET failed_attempts = 0, locked_until = NULL, last_login_at = :login_at, updated_at = :updated_at WHERE id = :id'
        )->execute([':login_at' => $now, ':updated_at' => $now, ':id' => $id]);
    }

    private function resetFailures(int $id): void
    {
        $this->db->pdo()->prepare(
            'UPDATE ' . $this->table() . ' SET failed_attempts = 0, locked_until = NULL WHERE id = :id'
        )->execute([':id' => $id]);
    }

    public function count(): int
    {
        return (int) $this->db->pdo()->query('SELECT COUNT(*) FROM ' . $this->table())->fetchColumn();
    }

    public function dropSchema(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS ' . $this->table());
    }

    private function table(): string { return $this->db->prefix() . 'user_accounts'; }
}
