<?php
declare(strict_types=1);
namespace Wx\Systems\Permission;

use Wx\Contracts\DatabaseInterface;

/**
 * 契约覆盖授权台账 —— 把「谁可以覆盖哪个契约」变成运行时可授权、可撤销、可审计的数据。
 *
 * v0.6/v0.7 装配期覆盖只留痕；v0.8 起 require_grant=true 时，覆盖必须先由
 * 具备 system.contract.override 权限的身份经受保护接口登记。装配期没有请求身份，
 * 因此「授权」这一动作天然属于运行时 —— 这正是本次语义变更的落点。
 *
 * 本类只做台账读写，不裁决、不认识请求对象；裁决在 PermissionSystem 的钩子与
 * ContractOverrideOperations 的执行者里。
 */
final class ContractOverrideLedger
{
    public function __construct(private DatabaseInterface $db) {}

    public function role(): string { return 'contract_override_ledger'; }

    /** upsert：同一 (contract, owner) 重复登记只更新，不产生脏数据 */
    public function grant(
        string $contract, string $owner, string $actor, string $reason, bool $active = true
    ): array {
        $now  = date('Y-m-d H:i:s');
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO ' . $this->table()
            . ' (contract,owner,granted_by,reason,active,created_at,updated_at)
               VALUES (:contract,:owner,:actor,:reason,:active,:created,:updated)
               ON DUPLICATE KEY UPDATE granted_by = VALUES(granted_by), reason = VALUES(reason),
                                      active = VALUES(active), updated_at = VALUES(updated_at)'
        );
        // 同名占位符在原生 prepare 下会报 HY093，两个时间列必须用不同名字
        $stmt->execute([
            ':contract' => $contract, ':owner' => $owner, ':actor' => $actor,
            ':reason'   => $reason,   ':active' => $active ? 1 : 0,
            ':created'  => $now, ':updated' => $now,
        ]);
        return ['changed' => true, 'contract' => $contract, 'owner' => $owner, 'active' => $active];
    }

    /** 装配期裁决用：精确匹配，找不到 / 已撤销 / 台账不可读一律视为未授权 */
    public function isAuthorized(string $contract, string $owner): bool
    {
        try {
            $stmt = $this->db->pdo()->prepare(
                'SELECT active FROM ' . $this->table() . ' WHERE contract = :c AND owner = :o LIMIT 1'
            );
            $stmt->execute([':c' => $contract, ':o' => $owner]);
            $row = $stmt->fetch();
            return $row !== false && (int) $row['active'] === 1;
        } catch (\Throwable) {
            // 表尚未建立（register 阶段早于 boot 建表）或库被拔掉：fail-closed，绝不静默放行
            return false;
        }
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        try {
            return $this->db->query(
                'SELECT contract,owner,granted_by,reason,active,created_at,updated_at FROM '
                . $this->table() . ' ORDER BY contract,owner'
            );
        } catch (\Throwable) {
            return [];
        }
    }

    private function table(): string
    {
        return $this->db->prefix() . 'permission_contract_overrides';
    }
}
