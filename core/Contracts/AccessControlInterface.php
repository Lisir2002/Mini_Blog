<?php
declare(strict_types=1);
namespace Wx\Contracts;

/**
 * 访问控制契约 —— 权限系统对外的唯一接口（「万象皆接口」在授权面的落点）。
 *
 * 核心只定义「询问点」，不认识任何具体权限名、不内置任何角色。
 * 权限点由各系统层用 permissions.json 自报；决策实现可整体替换
 * （MySQL / LDAP / 外部策略服务 / 全拒绝测试桩）。
 *
 * 决策三态是刻意的：allow 明确放行、deny 明确拒绝、abstain「无策略可依」。
 * abstain 在 allows() 里一律按拒绝处理 —— 默认拒绝（fail-closed）。
 */
interface AccessControlInterface
{
    public const ALLOW   = 'allow';
    public const DENY    = 'deny';
    public const ABSTAIN = 'abstain';

    /**
     * @param array<string, mixed>|null $identity 已认证身份；null 表示匿名
     * @param array<string, mixed>      $resource 资源上下文（type/id/status/author…）
     */
    public function decide(?array $identity, string $action, array $resource = []): string;

    /** 便捷判定：只有 allow 为真，deny 与 abstain 均视为拒绝。 */
    public function allows(?array $identity, string $action, array $resource = []): bool;

    /** @return array<string, array{name: string, system: string, description: string}> */
    public function catalog(): array;

    public function grant(string $role, string $permission): bool;
    public function revoke(string $role, string $permission): bool;

    /** @param array{type: string, id: string, subject: string, capability: string, allow: bool} $entry */
    public function setAcl(array $entry): bool;

    /** @return list<array<string, mixed>> 最近决策留痕 */
    public function decisions(): array;
}
