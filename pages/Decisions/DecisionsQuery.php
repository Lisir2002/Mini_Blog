<?php
declare(strict_types=1);
namespace Wx\Pages\Decisions;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Kernel;

/**
 * 决策轨迹的取数 —— 只读 permission.decisions 与 permission.check。
 *
 * 必须先 check 再 decisions：check 本身也会产生一条决策，先取快照的话
 * 页面上就看不到「刚才这次裁决」——而它正是用户点按钮想看的那个。
 *
 * permission.decisions 返回的是**当前进程内**最近 100 条，不是历史。
 * 历史留痕写进 permission_audit 表，本页不查库（页面只调接口），
 * 因此文案必须说清这一点，不能让「只有两条」看起来像「站点没人用过权限」。
 */
final class DecisionsQuery
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $identity
     * @return array{check: array<string, mixed>|null, asked: bool, entries: list<array<string, mixed>>,
     *               cache: array<string, mixed>, error: string}
     */
    public static function fetch(Kernel $kernel, array $query, ?array $identity): array
    {
        $container = $kernel->container();
        if (!$container->has(ApiRegistryInterface::class)) {
            return self::empty('接口清单未装配，无法读取决策轨迹。');
        }
        $registry = $container->resolve(ApiRegistryInterface::class);
        $action = trim((string) ($query['action'] ?? ''));
        $asked  = array_key_exists('action', $query);
        $check  = null;

        try {
            if ($asked && $action !== '') {
                $check = $registry->execute('permission.check', [
                    'action'        => $action,
                    'resource_type' => trim((string) ($query['type'] ?? '')),
                    'resource_id'   => trim((string) ($query['id'] ?? '')),
                    'field'         => trim((string) ($query['field'] ?? '')),
                    '_identity'     => $identity,
                ]);
            }
            $data = $registry->execute('permission.decisions', []);
        } catch (\Throwable $e) {
            return self::empty('决策轨迹读取失败：' . $e->getMessage());
        }

        $entries = [];
        foreach ((array) ($data['entries'] ?? []) as $entry) {
            if (is_array($entry)) $entries[] = $entry;
        }
        return [
            'check' => $check, 'asked' => $asked, 'entries' => $entries,
            'cache' => (array) ($data['cache'] ?? []), 'error' => '',
        ];
    }

    /**
     * @return array{check: null, asked: bool, entries: list<array<string, mixed>>,
     *               cache: array<string, mixed>, error: string}
     */
    private static function empty(string $error): array
    {
        return ['check' => null, 'asked' => false, 'entries' => [], 'cache' => [], 'error' => $error];
    }
}
