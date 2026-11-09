<?php
declare(strict_types=1);
namespace Wx\Contracts;

use PDO;

/**
 * 数据契约 —— 核心只定义接口，SQL 与表结构全部归属于各系统层自己。
 * 站点可整体替换实现（MySQL / SQLite / 远程 API），核心无需改动。
 */
interface DatabaseInterface
{
    public function pdo(): PDO;

    /** @param array<int|string, mixed> $params @return list<array<string, mixed>> */
    public function query(string $sql, array $params = []): array;

    /** @param array<int|string, mixed> $params */
    public function exec(string $sql, array $params = []): int;

    public function prefix(): string;
}
