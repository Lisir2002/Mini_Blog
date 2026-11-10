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
 *
 * v0.20 补连接超时：库不可达时 worker 不能无限等。PDO_MYSQL 的 ATTR_TIMEOUT 是
 * **连接**超时（实测：设 N 秒则 N 秒内失败；DSN 的 connect_timeout 在 mysqlnd 下无效，
 * 已放弃该写法）。语句级读超时属部署层配置（max_execution_time / innodb_lock_wait_timeout），
 * 不在应用连接里硬编码 —— 避免老版本 MySQL 因不识别参数导致整站连不上。
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
        private ?QueryProfilerInterface $profiler = null,
        private int $connectTimeout = 2
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
        $isMysql = str_starts_with($this->dsn, 'mysql:');
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ];
        if ($isMysql && defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET SESSION sql_mode='" . self::SQL_MODE . "'";
        }
        // 连接超时（秒）：PDO_MYSQL 的 ATTR_TIMEOUT 即连接阶段超时，0 表示沿用系统默认。
        if ($isMysql && $this->connectTimeout > 0) {
            $options[PDO::ATTR_TIMEOUT] = $this->connectTimeout;
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
