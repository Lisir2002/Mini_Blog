<?php
declare(strict_types=1);
namespace Wx\Pages\Users;

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Kernel;

/**
 * 用户列表取数 —— 只读 users.list；是否显示写按钮由 canManage 决定。
 *
 * 列表与按钮分开判断：能看用户不等于能改用户。canManage 用与接口同一个
 * AccessControlInterface，不另建一套「页面上的管理员」概念。
 */
final class UsersQuery
{
    public const LIMIT = 50;

    /**
     * @return array{items: list<array<string, mixed>>, total: int, shown: int,
     *               truncated: bool, error: string}
     */
    public static function fetch(Kernel $kernel, string $status, int $offset): array
    {
        $container = $kernel->container();
        if (!$container->has(ApiRegistryInterface::class)) {
            return self::empty('接口清单未装配，无法读取用户列表。');
        }
        try {
            $r = $container->resolve(ApiRegistryInterface::class)->execute('users.list', [
                'limit' => self::LIMIT, 'offset' => max(0, $offset), 'status' => $status,
            ]);
        } catch (\Throwable $e) {
            return self::empty('用户列表读取失败：' . $e->getMessage());
        }
        $items = array_values(array_filter((array) ($r['items'] ?? []), 'is_array'));
        return [
            'items' => $items, 'total' => (int) ($r['total'] ?? 0), 'shown' => count($items),
            'truncated' => (bool) ($r['truncated'] ?? false), 'error' => '',
        ];
    }

    /** @param array<string, mixed>|null $identity */
    public static function canManage(Kernel $kernel, ?array $identity): bool
    {
        $c = $kernel->container();
        $acl = $c->has(AccessControlInterface::class) ? $c->resolve(AccessControlInterface::class) : null;
        return $acl !== null && $identity !== null && $acl->allows($identity, 'user.manage');
    }

    /** @return array{items: list<array<string, mixed>>, total: int, shown: int, truncated: bool, error: string} */
    private static function empty(string $error): array
    {
        return ['items' => [], 'total' => 0, 'shown' => 0, 'truncated' => false, 'error' => $error];
    }
}
