<?php
declare(strict_types=1);
namespace Wx\Systems\User;

use Wx\Contracts\DatabaseInterface;
use Throwable;

/**
 * 账号的写侧仓储 —— 注册、改资料、改密、状态与角色变更、管理端列表。
 *
 * 与 PdoUserProvider 的分工：Provider 是契约实现，只服务「读身份 + 登录判定」；
 * 本类服务「账号生命周期」，两边的读写路径不互相穿透，避免一个类同时承担
 * 登录安全与后台管理两种职权。
 */
final class UserAccountStore
{
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_DISABLED = 'disabled';

    private const ROLE_PATTERN = '/^[a-z][a-z0-9_]{1,31}$/';

    public function __construct(private DatabaseInterface $db) {}

    /**
     * 注册新账号。用户名冲突不抛 PDOException —— 唯一索引报错会被分发器翻译成 50001，
     * 而「名字被占用」是客户端可修正的状态，必须表达成 409 而不是「服务器出错」。
     *
     * @return array{ok: bool, reason: string, user?: array<string, mixed>}
     */
    public function register(string $username, string $password, string $displayName, string $role = 'subscriber'): array
    {
        $now = date('Y-m-d H:i:s');
        try {
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO ' . $this->table() . ' (username,password_hash,display_name,role,status,created_at,updated_at)'
                . ' VALUES (:username,:hash,:display,:role,:status,:created_at,:updated_at)'
            );
            $stmt->execute([
                ':username' => $username,
                ':hash' => password_hash($password, PASSWORD_DEFAULT),
                ':display' => $displayName === '' ? $username : $displayName,
                ':role' => $role,
                ':status' => self::STATUS_ACTIVE,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        } catch (Throwable $e) {
            // 23000 = 唯一键冲突。只有「用户名已存在」会走到这里，其余错误继续上抛。
            if ((string) $e->getCode() === '23000') return ['ok' => false, 'reason' => 'duplicate'];
            throw $e;
        }
        return ['ok' => true, 'reason' => 'created', 'user' => $this->find((int) $this->db->pdo()->lastInsertId()) ?? []];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id,username,display_name,role,status,created_at,updated_at,last_login_at,locked_until'
            . ' FROM ' . $this->table() . ' WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) return null;
        $row['id'] = (int) $row['id'];
        return $row;
    }

    /** @param array<string, mixed> $fields 只接受 display_name，字段白名单在调用方之外也成立 */
    public function updateProfile(int $id, array $fields): bool
    {
        $allowed = [];
        if (array_key_exists('display_name', $fields)) {
            $allowed['display_name'] = self::clip(trim((string) $fields['display_name']), 64);
        }
        if ($allowed === []) return false;
        $sets = [];
        $params = [':id' => $id, ':now' => date('Y-m-d H:i:s')];
        foreach ($allowed as $column => $value) {
            $sets[] = $column . ' = :' . $column;
            $params[':' . $column] = $value;
        }
        $sets[] = 'updated_at = :now';
        $stmt = $this->db->pdo()->prepare('UPDATE ' . $this->table() . ' SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    public function changePassword(int $id, string $newPassword): void
    {
        $this->writePassword($id, $newPassword);
    }

    public function setStatus(int $id, string $status): void
    {
        $this->db->pdo()->prepare(
            'UPDATE ' . $this->table() . ' SET status = :status, updated_at = :now WHERE id = :id'
        )->execute([':status' => $status, ':now' => date('Y-m-d H:i:s'), ':id' => $id]);
    }

    public function setRole(int $id, string $role): void
    {
        $this->db->pdo()->prepare(
            'UPDATE ' . $this->table() . ' SET role = :role, updated_at = :now WHERE id = :id'
        )->execute([':role' => $role, ':now' => date('Y-m-d H:i:s'), ':id' => $id]);
    }

    /** 返回口令摘要，供「改密前校验旧口令」使用；不属于公开资料。 */
    public function passwordHash(int $id): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT password_hash FROM ' . $this->table() . ' WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $hash = $stmt->fetchColumn();
        return $hash === false ? null : (string) $hash;
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function paginate(int $limit, int $offset, string $status = ''): array
    {
        $where = '';
        $params = [];
        if ($status !== '') {
            $where = ' WHERE status = :status';
            $params[':status'] = $status;
        }
        $totalStmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM ' . $this->table() . $where);
        $totalStmt->execute($params);
        $total = (int) $totalStmt->fetchColumn();

        $stmt = $this->db->pdo()->prepare(
            'SELECT id,username,display_name,role,status,created_at,last_login_at FROM ' . $this->table()
            . $where . ' ORDER BY id ASC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset)
        );
        $stmt->execute($params);
        $items = $stmt->fetchAll();
        foreach ($items as $index => $row) {
            $row['id'] = (int) $row['id'];
            $items[$index] = $row;
        }
        return ['items' => array_values($items), 'total' => $total];
    }

    /** 管理员数量：用于「不能移除最后一个管理员」的判断。 */
    public function countRole(string $role): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM ' . $this->table() . ' WHERE role = :role');
        $stmt->execute([':role' => $role]);
        return (int) $stmt->fetchColumn();
    }

    public static function validRole(string $role): bool
    {
        return preg_match(self::ROLE_PATTERN, $role) === 1;
    }

    /**
     * 按字符截断，且不假设 mbstring 存在（本项目运行环境未装该扩展）。
     * 用 substr 兜底会截出半个 UTF-8 字节，后续 json_encode 直接返回 false ——
     * 一个显示名就能让整个响应变成空白，所以宁可用 iconv 也不用裸 substr。
     */
    public static function clip(string $value, int $limit): string
    {
        if (function_exists('mb_substr')) return mb_substr($value, 0, $limit, 'UTF-8');
        $clipped = iconv_substr($value, 0, $limit, 'UTF-8');
        return $clipped === false ? substr($value, 0, $limit) : $clipped;
    }

    /** 按字符计数；非法 UTF-8 返回 -1，由调用方决定怎么处理。 */
    public static function textLength(string $value): int
    {
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : iconv_strlen($value, 'UTF-8');
        return $length === false ? -1 : $length;
    }

    private function writePassword(int $id, string $password): void
    {
        $this->db->pdo()->prepare(
            'UPDATE ' . $this->table() . ' SET password_hash = :hash, updated_at = :now WHERE id = :id'
        )->execute([
            ':hash' => password_hash($password, PASSWORD_DEFAULT),
            ':now' => date('Y-m-d H:i:s'),
            ':id' => $id,
        ]);
    }

    private function table(): string { return $this->db->prefix() . 'user_accounts'; }
}
