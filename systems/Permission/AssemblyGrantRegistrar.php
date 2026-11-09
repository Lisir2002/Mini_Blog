<?php
declare(strict_types=1);
namespace Wx\Systems\Permission;

/**
 * 装配期覆盖的「系统层预授权」登记器 —— 从 PermissionSystem 拆出的单一职责协作者。
 *
 * 只做一件事：把「已注册系统层」在装配期发生的契约覆盖写入授权台账。
 * 判定规则刻意保守：
 *   - owner 必须形如 `system:<name>`；
 *   - `<name>` 必须在当前已注册系统层清单中真实存在。
 * 因此 `plugin:` / `theme:` / `page:` 以及冒充不存在的系统层名都不会被自动放行，
 * 必须由运行时身份经受保护接口授权。
 *
 * 输入是两个纯数组（覆盖记录 + 已知系统层名），不含请求、不碰容器，
 * 因此可以在守卫里直接构造用例验证，而不需要制造「register 阶段覆盖」的复杂时序。
 */
final class AssemblyGrantRegistrar
{
    public function __construct(private ContractOverrideLedger $ledger) {}

    public function role(): string { return 'assembly_grant_registrar'; }

    /**
     * @param list<array<string, mixed>> $overrides Container::overrides() 的留痕
     * @param list<string> $knownSystems 已注册系统层名
     * @return list<array{contract: string, owner: string}> 实际登记的系统层覆盖
     */
    public function register(array $overrides, array $knownSystems): array
    {
        $registered = [];
        foreach ($overrides as $override) {
            $owner = (string) ($override['to'] ?? '');
            $contract = (string) ($override['contract'] ?? '');
            if ($contract === '' || !str_starts_with($owner, 'system:')) continue;
            if (!in_array(substr($owner, 7), $knownSystems, true)) continue;
            $this->ledger->grant(
                $contract, $owner, 'assembly:auto', '已注册系统层的装配期覆盖自动登记', true
            );
            $registered[] = ['contract' => $contract, 'owner' => $owner];
        }
        return $registered;
    }
}
