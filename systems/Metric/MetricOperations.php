<?php
declare(strict_types=1);
namespace Wx\Systems\Metric;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Kernel;

/**
 * 统计接口执行者 —— 概览、序列、操作排行、指标字典。
 *
 * 四个接口共用一套读模型，区别只在「问到多细」：
 *   overview   一眼看全站：流量 / 错误率 / 分位耗时 / 业务存量
 *   series     单指标的时间序列（看趋势、看拐点）
 *   operations 按 operation 排行（谁被调得多、谁在失败、谁慢）
 *   catalog    指标字典本身（有哪些指标、什么类型、什么标签）
 *
 * 只读，不写业务数据；唯一副作用是概览请求会把 metric.collect 的存量
 * 作为 gauge 采样写入存储，让「今天的文章数」进入历史序列。
 */
final class MetricOperations
{
    public function __construct(
        private MetricQuery $query,
        private MetricRecorder $recorder,
        private Kernel $kernel
    ) {}

    public function register(ApiRegistryInterface $registry): void
    {
        $registry->registerOperation('stats.overview', function (array $in): array {
            $window = $this->query->window((string) ($in['window'] ?? '24h'));
            $gauges = $this->collectGauges();
            $data = $this->query->overview($window, $gauges);
            $data['collector'] = $this->recorder->status();
            return $data;
        });

        $registry->registerOperation('stats.series', function (array $in): array {
            $name = (string) ($in['name'] ?? '');
            if ($name === '') {
                throw new \InvalidArgumentException('name 必填，可用值见 stats.catalog');
            }
            $window = $this->query->window((string) ($in['window'] ?? '24h'));
            $labels = MetricLabels::parse((string) ($in['dimension'] ?? ''));
            return $this->query->series($name, $labels, $window, (int) ($in['limit'] ?? 500));
        });

        $registry->registerOperation('stats.operations', function (array $in): array {
            $window = $this->query->window((string) ($in['window'] ?? '24h'));
            return $this->query->operations($window, (int) ($in['limit'] ?? 50));
        });

        $registry->registerOperation('stats.catalog', fn(array $in): array => [
            'metrics' => $this->query->catalog(),
            'buckets_ms' => MetricCatalog::BUCKETS,
            'collector' => $this->recorder->status(),
        ]);
    }

    /**
     * 业务存量由各系统层通过 metric.collect 自报，统计层不认识文章 / 评论 / 账号。
     * 返回值原样进响应；同时写成 gauge 采样，历史序列才有「存量曲线」。
     *
     * @return list<array{name: string, labels: array<string, mixed>, value: float}>
     */
    private function collectGauges(): array
    {
        $samples = (array) $this->kernel->hooks()->apply('metric.collect', [], $this->kernel);
        $out = [];
        foreach ($samples as $sample) {
            if (!is_array($sample)) continue;
            $name = (string) ($sample['name'] ?? '');
            $value = $sample['value'] ?? null;
            if ($name === '' || !is_numeric($value)) continue;
            $labels = (array) ($sample['labels'] ?? []);
            $this->recorder->gauge($name, (float) $value, $labels);
            $out[] = ['name' => $name, 'labels' => $labels, 'value' => (float) $value];
        }
        return $out;
    }
}
