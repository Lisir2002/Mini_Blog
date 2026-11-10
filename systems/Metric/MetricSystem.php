<?php
declare(strict_types=1);
namespace Wx\Systems\Metric;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\AbstractSystem;
use Wx\Core\Kernel;
use Wx\Systems\Data\Db;

/**
 * 统计系统 —— 第 18 个系统层：让「现在发生了什么」变成可查询的事实。
 *
 * 它只做四件事：定义指标、采集采样、按小时聚合、回答四个问题。
 * 不读业务表（存量由各层通过 metric.collect 自报），不产 HTML（看板归主题），
 * 不阻塞请求（写入失败只降级并留痕）。
 *
 * 与既有层的协作全部走钩子：
 *   kernel.response / kernel.exception / api.executed / kernel.shutdown
 *   content.created / comment.* / attachment.uploaded / member.xp.awarded
 * 因此删掉本层，业务语义与其它层的装配完全不变。
 */
final class MetricSystem extends AbstractSystem
{
    public function name(): string { return 'metric'; }

    public function register(Kernel $kernel): void
    {
        $kernel->container()->singleton(
            MetricStoreInterface::class,
            static function () use ($kernel): MetricStoreInterface {
                $db = Db::optional($kernel);
                return $db === null ? new MemoryMetricStore() : new PdoMetricStore($db);
            },
            'system:metric',
            10
        );
    }

    public function boot(Kernel $kernel): void
    {
        /** @var MetricStoreInterface $store */
        $store = $kernel->container()->resolve(MetricStoreInterface::class);
        if ($store instanceof PdoMetricStore) $store->ensureSchema();

        // 字典在 boot 期定稿：插件的 metric.catalog 过滤器已全部注册完毕。
        $catalog = MetricCatalog::sanitize((array) $kernel->hooks()->apply(
            'metric.catalog', MetricCatalog::builtin(), $kernel
        ));
        $recorder = new MetricRecorder(
            $store,
            $catalog,
            (bool) $kernel->config()->get('metric.enabled', true)
        );
        $kernel->container()->singleton(
            MetricRecorder::class, static fn(): MetricRecorder => $recorder, 'system:metric:recorder', 10
        );

        (new MetricInstrumentation($recorder, $kernel))->attach();

        if (!$kernel->container()->has(ApiRegistryInterface::class)) return;
        /** @var ApiRegistryInterface $registry */
        $registry = $kernel->container()->resolve(ApiRegistryInterface::class);
        (new MetricOperations(new MetricQuery($store, $catalog), $recorder, $kernel))->register($registry);
    }
}
