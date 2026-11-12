<?php
declare(strict_types=1);
namespace Wx\Pages\Systems;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Kernel;

/**
 * 运维视图取数 —— 只读 health / layers / contracts / units / release。
 *
 * 四个接口各自代表一件事，任一失败都不该让整页消失：
 * 健康检查挂了仍要能看到版本与装配顺序，否则「页面打不开」和「站点真的坏了」
 * 会变成同一种症状。所以逐项取、逐项记错，页面按块降级。
 */
final class SystemsQuery
{
    /**
     * @return array{info: array<string, mixed>|null, health: array<string, mixed>|null,
     *               layers: array<string, mixed>|null, contracts: array<string, mixed>|null,
     *               units: list<array<string, mixed>>, release: array<string, mixed>|null,
     *               content: array{published: int, total: int}, errors: list<string>}
     */
    public static function fetch(Kernel $kernel): array
    {
        $out = ['info' => null, 'health' => null, 'layers' => null, 'contracts' => null,
            'units' => [], 'release' => null, 'content' => ['published' => 0, 'total' => 0],
            'errors' => []];
        $container = $kernel->container();
        if (!$container->has(ApiRegistryInterface::class)) {
            $out['errors'][] = '接口清单未装配，运维视图无法取数。';
            return $out;
        }
        $registry = $container->resolve(ApiRegistryInterface::class);

        // 显式字面量调用：消费矩阵靠扫描「execute('operation', ...」字面量认定消费方，
        // 拼接出来的名字（'system.' . $key）扫描器看不见，会把已消费接口误报成零消费。
        $ops = ['info' => 'system.info', 'health' => 'system.health',
            'layers' => 'system.layers', 'contracts' => 'system.contracts'];
        foreach ($ops as $key => $op) {
            $out[$key] = self::call($registry, $op, [], $out['errors'], $key);
        }
        $units = self::call($registry, 'lifecycle.units', [], $out['errors'], 'units');
        foreach ((array) ($units['units'] ?? []) as $unit) {
            if (is_array($unit)) $out['units'][] = $unit;
        }
        $out['release'] = self::call($registry, 'release.status', [], $out['errors'], 'release');

        // 内容规模：运维也要知道站点有多大 —— 表数量是结构的，发布数是业务的。
        // 失败不拖垮整页，只记一条错误，与上面四个接口同一套降级口径。
        $stats = self::call($registry, 'content.stats', [], $out['errors'], 'stats');
        if ($stats !== null) {
            $out['content'] = ['published' => (int) ($stats['published'] ?? 0),
                'total' => (int) ($stats['total'] ?? 0)];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $in
     * @param list<string> $errors
     * @return array<string, mixed>|null
     */
    private static function call(ApiRegistryInterface $registry, string $operation, array $in,
        array &$errors, string $label): ?array
    {
        try {
            return $registry->execute($operation, $in);
        } catch (\Throwable $e) {
            $errors[] = $label . '：' . $e->getMessage();
            return null;
        }
    }
}
