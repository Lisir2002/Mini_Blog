<?php
declare(strict_types=1);
namespace Wx\Systems\Permission;

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Kernel;
use Wx\Systems\Api\ApiOperationException;
use Wx\Systems\Data\Db;

/**
 * 契约覆盖的两个规范化 operation —— 运行时身份鉴权的入口。
 *
 * 授权登记必须携带由 RequestGuard 注入的 _identity，并由同一个
 * AccessControlInterface 复核 system.contract.override 权限（纵深防御：
 * 即使绕过 RequestGuard 直接调用执行者，也不会变成无主授权）。
 */
final class ContractOverrideOperations
{
    public function __construct(
        private Kernel $kernel,
        private AccessControlInterface $acl
    ) {}

    public function role(): string { return 'contract_override_operations'; }

    public function register(ApiRegistryInterface $registry): void
    {
        $registry->registerOperation('permission.overrides', function (array $in): array {
            return $this->overview();
        });

        $registry->registerOperation('permission.override.grant', function (array $in): array {
            $contract = trim((string) ($in['contract'] ?? ''));
            $owner    = trim((string) ($in['owner'] ?? ''));
            if ($contract === '' || $owner === '') {
                throw new ApiOperationException('contract 与 owner 必填', 42201);
            }
            $identity = $in['_identity'] ?? null;
            if (!$this->acl->allows($identity, 'system.contract.override')) {
                throw new ApiOperationException('权限决策未放行（fail-closed）', 40303);
            }
            $db = Db::optional($this->kernel);
            if ($db === null) {
                throw new ApiOperationException('权限层未连库，无法登记授权', 50001);
            }
            return (new ContractOverrideLedger($db))->grant(
                $contract,
                $owner,
                (string) ($identity['username'] ?? 'unknown'),
                (string) ($in['reason'] ?? ''),
                !((bool) ($in['remove'] ?? false))
            );
        });
    }

    /** 装配期实际覆盖 vs 运行时授权台账的对账视图 */
    private function overview(): array
    {
        $db      = Db::optional($this->kernel);
        $ledger  = $db !== null ? new ContractOverrideLedger($db) : null;
        $require = $this->kernel->config()->get('permission.contract_override.require_grant', false) === true;
        $overrides = $this->kernel->container()->overrides();

        foreach ($overrides as &$row) {
            $row['authorized'] = !$require
                || ($ledger !== null && $ledger->isAuthorized((string) $row['contract'], (string) $row['to']));
        }
        unset($row);

        return [
            'overrides'     => $overrides,
            'grants'        => $ledger?->list() ?? [],
            'require_grant' => $require,
            'unauthorized'  => array_values(array_filter(
                $overrides, static fn(array $row): bool => !$row['authorized']
            )),
        ];
    }
}
