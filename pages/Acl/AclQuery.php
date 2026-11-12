<?php
declare(strict_types=1);
namespace Wx\Pages\Acl;

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Kernel;
use Wx\Systems\Api\ApiOperationException;

/**
 * ACL 页取数 —— 四个面板各自取、各自降级。
 *
 * 两条约束决定了页面形态：
 *  1. `permission.acl.list` 没有按资源过滤的参数，只返回最近 100 条。
 *     所以「这篇文章的 ACL」只能在页面层按 type+id 筛，且必须写明「超过 100 条会漏」；
 *  2. `permission.acl.set` 的 id 不校验文章存在，因此先按 content.posts 选文章
 *     （这就是这一组能力缺的「选中某篇文章」浏览路径），选中后再用 content.post 复核。
 *
 * `users.view` 在这里也作为读路径被消费：groups 面板用它查 user:<id> 到底是谁，
 * 避免管理员把成员写成一个自己都解释不清的数字。
 */
final class AclQuery
{
    public const POST_LIMIT = 20;

    /**
     * @param array<string, mixed>|null $identity
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public static function fetch(Kernel $kernel, ?array $identity, array $query): array
    {
        $panel = trim((string) ($query['panel'] ?? 'acl'));
        $slug  = trim((string) ($query['slug'] ?? ''));
        $group = trim((string) ($query['group'] ?? ''));
        $user  = trim((string) ($query['user'] ?? ''));
        if (!in_array($panel, AclInput::PANELS, true)) {
            return self::fail(422, 'panel 只能是 ' . implode(' / ', AclInput::PANELS) . '。');
        }
        if ($slug !== '' && preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/', $slug) !== 1) {
            return self::fail(422, '文章 slug 只能用小写字母、数字、连字符。');
        }
        if ($group !== '' && preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $group) !== 1) {
            return self::fail(422, '分组标识格式不合法。');
        }
        if ($user !== '' && preg_match('/^\d{1,18}$/', $user) !== 1) {
            return self::fail(422, 'user 必须是数字账号 ID。');
        }

        $out = ['ok' => true, 'status' => 200, 'error' => '', 'panel' => $panel,
            'slug' => $slug, 'group' => $group, 'user' => $user,
            'posts' => null, 'posts_fail' => '', 'post' => null, 'post_state' => 'idle', 'post_error' => '',
            'entries' => null, 'entries_total' => 0, 'entries_fail' => '', 'filtered' => [],
            'groups' => null, 'groups_fail' => '', 'tree' => null, 'tree_fail' => '',
            'overrides' => null, 'overrides_fail' => '',
            'roles' => [], 'catalog' => [], 'user_view' => null, 'user_state' => 'idle', 'user_error' => '',
            'can' => self::can($kernel, $identity)];

        $container = $kernel->container();
        if (!$container->has(ApiRegistryInterface::class)) {
            return self::fail(503, '接口清单未装配，授权数据无法读取。');
        }
        $registry = $container->resolve(ApiRegistryInterface::class);
        self::common($registry, $panel, $out);
        self::byPanel($registry, $panel, $slug, $user, $out);
        return $out;
    }

    /** @param array<string, mixed> $out */
    private static function common(ApiRegistryInterface $registry, string $panel, array &$out): void
    {
        if (in_array($panel, ['acl', 'groups'], true)) {
            try {
                $out['roles'] = (array) ($registry->execute('permission.roles', [])['roles'] ?? []);
                $out['catalog'] = (array) ($registry->execute('permission.catalog', [])['permissions'] ?? []);
            } catch (\Throwable $e) {
                $out['roles'] = [];
                $out['catalog'] = [];
            }
        }
    }

    /** @param array<string, mixed> $out */
    private static function byPanel(ApiRegistryInterface $registry, string $panel, string $slug,
        string $user, array &$out): void
    {
        if ($panel === 'acl') {
            self::acl($registry, $slug, $out);
        } elseif ($panel === 'groups') {
            self::groups($registry, $user, $out);
        } elseif ($panel === 'tree') {
            self::one($registry, 'permission.role.tree', 'tree', 'tree_fail', $out);
        } else {
            self::one($registry, 'permission.overrides', 'overrides', 'overrides_fail', $out);
        }
    }

    /** @param array<string, mixed> $out */
    private static function acl(ApiRegistryInterface $registry, string $slug, array &$out): void
    {
        try {
            $r = $registry->execute('permission.acl.list', []);
            $out['entries'] = array_values(array_filter((array) ($r['entries'] ?? []), 'is_array'));
            $out['entries_total'] = count($out['entries']);
        } catch (\Throwable $e) {
            $out['entries_fail'] = $e->getMessage();
        }
        foreach ((array) $out['entries'] as $row) {
            if ((string) ($row['resource_type'] ?? '') !== 'post') continue;
            if ($slug !== '' && (string) ($row['resource_id'] ?? '') !== $slug) continue;
            $out['filtered'][] = $row;
        }
        if ($slug === '') {
            try {
                $r = $registry->execute('content.posts', ['status' => 'any', 'limit' => self::POST_LIMIT]);
                $out['posts'] = array_values(array_filter((array) ($r['items'] ?? []), 'is_array'));
            } catch (\Throwable $e) {
                $out['posts_fail'] = $e->getMessage();
            }
            return;
        }
        try {
            $out['post'] = $registry->execute('content.post', ['slug' => $slug]);
            $out['post_state'] = 'found';
        } catch (ApiOperationException $e) {
            $out['post_state'] = $e->apiCode === 40403 ? 'missing' : 'failed';
            $out['post_error'] = $e->getMessage();
        } catch (\Throwable $e) {
            $out['post_state'] = 'failed';
            $out['post_error'] = $e->getMessage();
        }
    }

    /** @param array<string, mixed> $out */
    private static function groups(ApiRegistryInterface $registry, string $user, array &$out): void
    {
        self::one($registry, 'permission.groups', 'groups', 'groups_fail', $out);
        if ($user === '') return;
        try {
            $out['user_view'] = (array) ($registry->execute('users.view', ['key' => $user])['user'] ?? []);
            $out['user_state'] = $out['user_view'] === [] ? 'missing' : 'found';
        } catch (ApiOperationException $e) {
            $out['user_state'] = $e->apiCode === 40403 ? 'missing' : 'failed';
            $out['user_error'] = $e->getMessage();
        } catch (\Throwable $e) {
            $out['user_state'] = 'failed';
            $out['user_error'] = $e->getMessage();
        }
    }

    /** @param array<string, mixed> $out */
    private static function one(ApiRegistryInterface $registry, string $op, string $key,
        string $failKey, array &$out): void
    {
        try {
            $out[$key] = $registry->execute($op, []);
        } catch (\Throwable $e) {
            $out[$failKey] = $e->getMessage();
        }
    }

    /** @param array<string, mixed>|null $identity */
    private static function can(Kernel $kernel, ?array $identity): bool
    {
        $container = $kernel->container();
        if (!$container->has(AccessControlInterface::class)) return false;
        $acl = $container->resolve(AccessControlInterface::class);
        return $acl instanceof AccessControlInterface
            && $acl->allows($identity, 'permission.manage');
    }

    /** @return array<string, mixed> */
    private static function fail(int $status, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'error' => $message, 'panel' => 'acl',
            'slug' => '', 'group' => '', 'user' => '', 'posts' => null, 'posts_fail' => '',
            'post' => null, 'post_state' => 'idle', 'post_error' => '',
            'entries' => null, 'entries_total' => 0, 'entries_fail' => '', 'filtered' => [],
            'groups' => null, 'groups_fail' => '', 'tree' => null, 'tree_fail' => '',
            'overrides' => null, 'overrides_fail' => '',
            'roles' => [], 'catalog' => [], 'user_view' => null, 'user_state' => 'idle',
            'user_error' => '', 'can' => false];
    }
}
