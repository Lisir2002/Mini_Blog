<?php
declare(strict_types=1);
namespace Wx\Systems\Data;

use PDO;
use PDOStatement;

/**
 * 计数版连接 —— 把「所有执行都经过这里」变成结构性保证，而不是纪律要求。
 *
 * 为什么必须在这一层：各仓储普遍直接调用 `$db->pdo()->prepare()`，
 * 如果在 DatabaseInterface::query/exec 上计数，会漏掉大部分语句。
 * 继承 PDO 并把语句类换成 ProfiledStatement，才能覆盖 prepare→execute 这条路。
 */
final class CountingPdo extends PDO
{
    /** @param array<int, mixed> $options */
    public function __construct(
        string $dsn,
        string $user,
        string $pass,
        array $options,
        private QueryProfilerInterface $profiler
    ) {
        $options[PDO::ATTR_STATEMENT_CLASS] = [ProfiledStatement::class, [$profiler]];
        parent::__construct($dsn, $user, $pass, $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $start = microtime(true);
        $statement = parent::query($query, $fetchMode, ...$fetchModeArgs);
        $this->profiler->hit($query, (microtime(true) - $start) * 1000.0);
        return $statement;
    }

    public function exec(string $statement): int|false
    {
        $start = microtime(true);
        $rows = parent::exec($statement);
        $this->profiler->hit($statement, (microtime(true) - $start) * 1000.0);
        return $rows;
    }
}
