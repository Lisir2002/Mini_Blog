<?php
declare(strict_types=1);
namespace Wx\Systems\Database;

use PDO;
use RuntimeException;
use Wx\Contracts\DatabaseInterface;

/** MySQL / PDO 实现。连接是惰性的：未配置 DSN 时装配阶段不会报错，首次使用才抛异常。 */
final class PdoDatabase implements DatabaseInterface
{
    private ?PDO $connection = null;

    public function __construct(
        private string $dsn,
        private string $user,
        private string $pass,
        private string $prefix
    ) {}

    public function pdo(): PDO
    {
        if ($this->connection === null) {
            if ($this->dsn === '') {
                throw new RuntimeException('未配置 database.dsn；请接入 MySQL，或替换 DatabaseInterface 的实现');
            }
            $this->connection = new PDO($this->dsn, $this->user, $this->pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return $this->connection;
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
