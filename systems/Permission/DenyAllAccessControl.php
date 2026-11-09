<?php
declare(strict_types=1);
namespace Wx\Systems\Permission;

use Wx\Contracts\AccessControlInterface;

/**
 * 拔库 / 停用权限层时的回退实现 —— 决策恒为 abstain（= 默认拒绝）。
 *
 * 它仍返回权限声明清单，让站长能看到「有哪些权限点」，但不会放行任何受保护操作。
 * 公开只读内容不受影响（见 ContentVisibility 的 abstain 分支），
 * 因此「删光数据库」不会把站点彻底锁死，只会关闭写与受限数据。
 */
final class DenyAllAccessControl implements AccessControlInterface
{
    public function __construct(private PermissionRegistry $registry) {}

    public function role(): string { return 'deny_all_access'; }

    public function decide(?array $identity, string $action, array $resource = []): string
    {
        return self::ABSTAIN;
    }

    public function allows(?array $identity, string $action, array $resource = []): bool
    {
        return false;
    }

    public function catalog(): array { return $this->registry->all(); }

    public function grant(string $role, string $permission): bool { return false; }
    public function revoke(string $role, string $permission): bool { return false; }
    public function setAcl(array $entry): bool { return false; }
    public function decisions(): array { return []; }
}
