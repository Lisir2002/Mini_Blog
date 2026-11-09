<?php
declare(strict_types=1);
namespace Wx\Plugins\Performance;

use Wx\Contracts\PackageInterface;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;
use Wx\Systems\Data\QueryProfilerInterface;

/**
 * 可选性能治理包 —— 不是系统层，是「能装能卸」的增强。
 *
 * 只用 core 已有的 kernel.shutdown 出口（渲染后唯一可编程点），core 零改动。
 * config.performance.enabled=false 时一个钩子都不注册：关闭即等于没装。
 * 预算超限只借既有的 kernel.trace 留痕（LogSystem 已是消费者），不新增事件、不新增 operation。
 */
final class PerformancePlugin implements PackageInterface
{
    public function slug(): string { return 'performance'; }

    public function register(Kernel $kernel): void
    {
        if (!$kernel->config()->get('performance.enabled', false)) return;

        $economy = new ResponseEconomy(
            (int) $kernel->config()->get('performance.economy.min_bytes', 256),
            (bool) $kernel->config()->get('performance.economy.gzip', true),
            (int) $kernel->config()->get('performance.economy.max_age', 60)
        );
        $budget = new PerformanceBudget(
            (int) $kernel->config()->get('performance.budget.max_queries', 40),
            (float) $kernel->config()->get('performance.budget.max_ms', 120.0)
        );

        $kernel->hooks()->on(
            'kernel.shutdown',
            static function (Request $request, Response $response, Kernel $k) use ($economy, $budget): void {
                $container = $k->container();
                $queries = 0;
                $sqlMs = 0.0;
                if ($container->has(QueryProfilerInterface::class)) {
                    $profiler = $container->resolve(QueryProfilerInterface::class);
                    $queries = $profiler->count();
                    $sqlMs = $profiler->totalMs();
                }

                $verdict = $budget->verdict($queries, $sqlMs);
                $response->setHeader('Server-Timing', $budget->serverTiming($verdict));
                if ($verdict['exceeded']) {
                    $response->setHeader('X-Wx-Budget', implode(',', $verdict['reasons']));
                    $k->hooks()->emit('kernel.trace', '性能预算超限', [
                        'queries'     => $queries,
                        'sql_ms'      => $sqlMs,
                        'max_queries' => $verdict['max_queries'],
                        'max_ms'      => $verdict['max_ms'],
                    ]);
                }
                $economy->apply($request, $response);
            },
            30
        );
    }
}
