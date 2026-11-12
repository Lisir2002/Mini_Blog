<?php
declare(strict_types=1);
namespace Wx\Pages\Emojis;

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Kernel;

/**
 * 表情目录取数 —— 一次拿全量，展示筛选放页面层。
 *
 * 为什么不给 emojis.list 传 kind：短码占用预查必须基于全量清单。按 kind 过滤后再
 * 判「这个短码有没有被占用」会把内置表情漏掉，接口随后抛 40901 —— 页面能提前说清的
 * 事不该甩给接口。目录没有分页参数，total 就是全量，但仍与「读取失败」分开表达。
 */
final class EmojisQuery
{
    public const KINDS = ['any', 'unicode', 'kaomoji', 'image'];
    public const LIST_LIMIT = 120;

    /** @param array<string, mixed>|null $identity @param array<string, mixed> $query
     *  @return array<string, mixed> */
    public static function fetch(Kernel $kernel, ?array $identity, array $query): array
    {
        $kind = (string) ($query['kind'] ?? 'any');
        if (!in_array($kind, self::KINDS, true)) $kind = 'any';
        $out = ['ok' => true, 'status' => 200, 'error' => '', 'kind' => $kind,
            'items' => [], 'custom' => [], 'codes' => [], 'custom_codes' => [],
            'total' => 0, 'shown' => 0, 'builtin_count' => 0, 'custom_count' => 0,
            'state' => 'idle', 'fail' => '', 'truncated' => false,
            'can_manage' => self::can($kernel, $identity, 'emoji.manage')];

        $container = $kernel->container();
        if (!$container->has(ApiRegistryInterface::class)) {
            $out['ok'] = false; $out['status'] = 503;
            $out['error'] = '接口清单未装配，表情目录无法读取。';
            return $out;
        }
        $registry = $container->resolve(ApiRegistryInterface::class);
        try {
            $r = $registry->execute('emojis.list', []);
            $all = array_values(array_filter((array) ($r['items'] ?? []), 'is_array'));
            $out['total'] = (int) ($r['total'] ?? count($all));
            $out['truncated'] = count($all) > self::LIST_LIMIT;
            $all = array_slice($all, 0, self::LIST_LIMIT);
            foreach ($all as $e) {
                $code = strtolower(trim((string) ($e['code'] ?? '')));
                if ($code === '') continue;
                $out['codes'][] = $code;
                if ((bool) ($e['custom'] ?? false)) $out['custom_codes'][] = $code;
            }
            foreach ($all as $e) {
                if ($kind !== 'any' && (string) ($e['kind'] ?? '') !== $kind) continue;
                $out['items'][] = $e;
            }
            $out['custom'] = array_values(array_filter($all,
                static fn(array $e): bool => (bool) ($e['custom'] ?? false)));
            $out['builtin_count'] = count($all) - count($out['custom']);
            $out['custom_count'] = count($out['custom']);
            $out['shown'] = count($out['items']);
            $out['state'] = $all === [] ? 'empty' : 'ok';
        } catch (\Throwable $e) {
            $out['state'] = 'failed';
            $out['fail'] = $e->getMessage();
        }
        return $out;
    }

    /** @param array<string, mixed>|null $identity */
    private static function can(Kernel $kernel, ?array $identity, string $permission): bool
    {
        $container = $kernel->container();
        if (!$container->has(AccessControlInterface::class)) return false;
        $acl = $container->resolve(AccessControlInterface::class);
        return $acl instanceof AccessControlInterface && $acl->allows($identity, $permission);
    }
}
