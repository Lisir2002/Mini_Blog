<?php
declare(strict_types=1);
namespace Wx\Systems\Data;

/**
 * 查询观测契约 —— 数据层对外提供的唯一「效率视角」。
 *
 * 存在的理由很具体：没有计数器时，「列表页打了几条 SQL」只能靠猜，
 * 于是 N+1 既发现不了、也没法写成守卫。计数是让效率变成可断言事实的前提。
 */
interface QueryProfilerInterface
{
    /** 记录一条已执行的语句（毫秒）。实现方自行决定是否截断 SQL 文本。 */
    public function hit(string $sql, float $ms): void;

    public function count(): int;

    public function totalMs(): float;

    /** @return list<array{sql: string, ms: float}> 超过阈值的语句，按耗时降序，最多保留 50 条 */
    public function slow(float $thresholdMs = 50.0): array;

    public function reset(): void;
}
