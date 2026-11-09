<?php
declare(strict_types=1);
namespace Wx\Plugins\Performance;

/**
 * 请求级性能预算 —— 纯计算，只判定与格式化，不改变任何执行结果。
 *
 * 为什么不做成「自动限流 / 自动降级」：预算超了应该被看见、被追查，
 * 而不是悄悄改掉业务行为。这里只产出结论，留痕由编排者决定。
 */
final class PerformanceBudget
{
    public function __construct(
        private int $maxQueries,
        private float $maxMs
    ) {}

    /**
     * @return array{queries:int, sql_ms:float, max_queries:int, max_ms:float,
     *               exceeded:bool, reasons:list<string>}
     */
    public function verdict(int $queries, float $sqlMs): array
    {
        $reasons = [];
        if ($queries > $this->maxQueries) $reasons[] = 'queries';
        if ($sqlMs > $this->maxMs) $reasons[] = 'sql_ms';

        return [
            'queries'     => $queries,
            'sql_ms'      => round($sqlMs, 2),
            'max_queries' => $this->maxQueries,
            'max_ms'      => $this->maxMs,
            'exceeded'    => $reasons !== [],
            'reasons'     => $reasons,
        ];
    }

    /** RFC 更常用的 Server-Timing 形状：让每个响应自带工作量证据。 */
    public function serverTiming(array $verdict): string
    {
        return sprintf(
            'sql;desc="%d queries";dur=%.2f',
            (int) $verdict['queries'],
            (float) $verdict['sql_ms']
        );
    }
}
