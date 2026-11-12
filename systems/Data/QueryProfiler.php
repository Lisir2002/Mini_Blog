<?php
declare(strict_types=1);
namespace Wx\Systems\Data;

/**
 * 查询计数器 —— 只做计数与慢查询留样，不改变任何执行行为。
 *
 * 为什么不用日志：日志是抽查，计数器是断言。守卫要的是「这一次请求恰好 N 条」，
 * 而不是「翻日志看看有没有异常」。因此这里保持内存态、可按请求 reset。
 */
final class QueryProfiler implements QueryProfilerInterface
{
    private const KEEP = 50;
    private const SQL_MAX = 200;

    private int $count = 0;
    private float $totalMs = 0.0;
    /** @var list<array{sql: string, ms: float}> */
    private array $slow = [];

    public function hit(string $sql, float $ms): void
    {
        $this->count++;
        $this->totalMs += $ms;
        if ($ms < 50.0) return;
        $this->slow[] = ['sql' => self::cut($sql), 'ms' => round($ms, 2)];
        if (count($this->slow) > self::KEEP) array_shift($this->slow);
    }

    public function count(): int { return $this->count; }

    public function totalMs(): float { return round($this->totalMs, 2); }

    public function slow(float $thresholdMs = 50.0): array
    {
        $rows = array_values(array_filter($this->slow, static fn(array $r): bool => $r['ms'] >= $thresholdMs));
        usort($rows, static fn(array $a, array $b): int => $b['ms'] <=> $a['ms']);
        return $rows;
    }

    public function reset(): void
    {
        $this->count = 0;
        $this->totalMs = 0.0;
        $this->slow = [];
    }

    /** 不假设装了 mbstring：没有就按字节截断，SQL 文本以 ASCII 为主。 */
    private static function cut(string $sql): string
    {
        if (strlen($sql) <= self::SQL_MAX) return $sql;
        return Utf8Text::clip($sql, self::SQL_MAX);
    }
}
