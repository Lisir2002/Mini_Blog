<?php
declare(strict_types=1);
namespace Wx\Systems\Data;

use PDO;
use RuntimeException;
use Wx\Contracts\DatabaseInterface;

/**
 * MySQL / PDO 实现。连接是惰性的：未配置 DSN 时装配阶段不会报错，首次使用才抛异常。
 *
 * v0.14 起多做了两件事：
 * 1. 显式设置严格模式 —— 不依赖服务器默认值。宽松模式下超长字符串会被静默截断，
 *    那正是「静默改变用户意图」，必须由连接自己保证。
 * 2. 挂上查询计数器 —— 通过替换语句类覆盖 prepare→execute，而不是只在 query/exec 上计数。
 */
final class PdoDatabase implements DatabaseInterface
{
    private ?PDO $connection = null;

    private const SQL_MODE = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    public function __construct(
        private string $dsn,
        private string $user,
        private string $pass,
        private string $prefix,
        private ?QueryProfilerInterface $profiler = null
    ) {}

    public function pdo(): PDO
    {
        if ($this->connection === null) {
            if ($this->dsn === '') {
                throw new RuntimeException('未配置 database.dsn；请接入 MySQL，或替换 DatabaseInterface 的实现');
            }
            $this->connection = $this->connect();
        }
        return $this->connection;
    }

    private function connect(): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ];
        if (str_starts_with($this->dsn, 'mysql:') && defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET SESSION sql_mode='" . self::SQL_MODE . "'";
        }
        return $this->profiler === null
            ? new PDO($this->dsn, $this->user, $this->pass, $options)
            : new CountingPdo($this->dsn, $this->user, $this->pass, $options, $this->profiler);
    }

    public function query(string $sql, array $params = []): array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public function exec(string $sql, array $params = []): int
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);
        return $statement->rowCount();
    }

    public function prefix(): string { return $this->prefix; }
}
