<?php
declare(strict_types=1);
namespace Wx\Pages\Systems;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Kernel;

/**
 * 运维视图取数 —— 只读 health / layers / contracts / units / release / history / task。
 *
 * 每个接口各自代表一件事，任一失败都不该让整页消失：
 * 健康检查挂了仍要能看到版本与装配顺序，否则「页面打不开」和「站点真的坏了」
 * 会变成同一种症状。所以逐项取、逐项记错，页面按块降级。
 *
 * 按需探查（verify / releases / task）由 GET 查询参数触发：这三个都是读操作
 * （摘要比对、快照清单、任务相位），GET 幂等；页面不提供任何写按钮。
 */
final class SystemsQuery
{
    /** @var list<string> lifecycle.verify / lifecycle.releases 接受的单元类型 */
    private const UNIT_TYPES = ['core', 'system', 'theme', 'plugin'];

    /**
     * @param array{verify?: string, releases?: string, task?: string} $probe GET 查询透传
     * @return array{info: array<string, mixed>|null, health: array<string, mixed>|null,
     *               layers: array<string, mixed>|null, contracts: array<string, mixed>|null,
     *               units: list<array<string, mixed>>, release: array<string, mixed>|null,
     *               history: array<string, mixed>|null, content: array{published: int, total: int},
     *               verify: array<string, mixed>|null, releases: array<string, mixed>|null,
     *               task: array<string, mixed>|null, probe: array{verify: string, releases: string, task: string},
     *               errors: list<string>}
     */
    public static function fetch(Kernel $kernel, array $probe = []): array
    {
        $out = ['info' => null, 'health' => null, 'layers' => null, 'contracts' => null,
            'units' => [], 'release' => null, 'history' => null,
            'content' => ['published' => 0, 'total' => 0],
            'verify' => null, 'releases' => null, 'task' => null,
            'probe' => ['verify' => '', 'releases' => '', 'task' => ''],
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
        $out['history'] = self::call($registry, 'release.history',
            ['limit' => 20], $out['errors'], 'history');

        // 内容规模：运维也要知道站点有多大 —— 表数量是结构的，发布数是业务的。
        // 失败不拖垮整页，只记一条错误，与上面四个接口同一套降级口径。
        $stats = self::call($registry, 'content.stats', [], $out['errors'], 'stats');
        if ($stats !== null) {
            $out['content'] = ['published' => (int) ($stats['published'] ?? 0),
                'total' => (int) ($stats['total'] ?? 0)];
        }

        // 按需探查：解析失败不执行，把原因记进 errors —— 输入错是请求问题，不是故障。
        if (isset($probe['verify']) && $probe['verify'] !== '') {
            $unit = self::parseUnit((string) $probe['verify'], $out['errors'], 'verify');
            if ($unit !== null) {
                $out['probe']['verify'] = (string) $probe['verify'];
                $out['verify'] = self::call($registry, 'lifecycle.verify',
                    $unit, $out['errors'], 'verify');
            }
        }
        if (isset($probe['releases']) && $probe['releases'] !== '') {
            $unit = self::parseUnit((string) $probe['releases'], $out['errors'], 'releases');
            if ($unit !== null) {
                $out['probe']['releases'] = (string) $probe['releases'];
                $out['releases'] = self::call($registry, 'lifecycle.releases',
                    $unit, $out['errors'], 'releases');
            }
        }
        if (isset($probe['task']) && $probe['task'] !== '') {
            $id = (string) $probe['task'];
            if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) !== 1) {
                $out['errors'][] = 'task：任务 id 只能是 1–64 位字母、数字、下划线或短横线。';
            } else {
                $out['probe']['task'] = $id;
                $out['task'] = self::call($registry, 'state.task.status',
                    ['id' => $id], $out['errors'], 'task');
            }
        }
        return $out;
    }

    /**
     * 「type:slug」→ 接口参数；类型不在白名单、slug 格式非法都不放行。
     *
     * @param list<string> $errors
     * @return array{type: string, slug: string}|null
     */
    private static function parseUnit(string $raw, array &$errors, string $label): ?array
    {
        $parts = explode(':', $raw, 2);
        if (count($parts) !== 2 || !in_array($parts[0], self::UNIT_TYPES, true)
            || strlen($parts[1]) > 64
            || preg_match('/^[a-z0-9][a-z0-9_-]*$/', $parts[1]) !== 1) {
            $errors[] = $label . '：单元要写成 type:slug，type 只能是 '
                . implode(' / ', self::UNIT_TYPES)
                . '，slug 是 1–64 位小写字母、数字、短横线或下划线。';
            return null;
        }
        return ['type' => $parts[0], 'slug' => $parts[1]];
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
