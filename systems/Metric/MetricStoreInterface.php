<?php
declare(strict_types=1);
namespace Wx\Systems\Metric;

/**
 * 指标存储契约 —— 只描述「采样点怎么写、怎么读、怎么裁剪」，不描述采集什么。
 *
 * 两种实现必须语义一致：
 *   - PdoMetricStore   落 MySQL，跨请求 / 跨进程持久；
 *   - MemoryMetricStore 只在当前进程内有效，库不可用时让站点仍能回答「本进程发生了什么」。
 *
 * 采集器永远不知道底层是哪一种；`durable()` 把「这份数据能不能当真」显式暴露给查询端。
 */
interface MetricStoreInterface
{
    /**
     * 写入一批采样点。点结构：
     *   bucket_at 小时对齐时间 / name 指标名 / dimension 标签串 / kind 类型
     *   le 直方图桶上界（非直方图为空）/ hits 命中数 / total 观测值之和
     *
     * @param list<array{bucket_at: string, name: string, dimension: string,
     *                   kind: string, le: string, hits: int, total: float}> $points
     */
    public function write(array $points): void;

    /**
     * 原始时间序列，按 bucket_at 升序。
     * @return list<array{bucket_at: string, dimension: string, kind: string,
     *                    le: string, hits: int, total: float}>
     */
    public function series(string $name, string $since, string $dimension = '', int $limit = 0): array;

    /**
     * 窗口内按维度聚合的总量（计数器 / 仪表）或按桶命中数（直方图）。
     * @return list<array{dimension: string, le: string, hits: int, total: float}>
     */
    public function summary(string $name, string $since): array;

    /** 删除 bucket_at 早于 $before 的采样点，返回删除行数。 */
    public function prune(string $before): int;

    /** false 表示数据随进程消失，不能当历史账本用。 */
    public function durable(): bool;

    /** 存储驱动标识，出现在统计响应的 collector 段，便于判断数据来源。 */
    public function driver(): string;
}
