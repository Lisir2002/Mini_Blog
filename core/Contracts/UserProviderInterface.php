<?php
declare(strict_types=1);
namespace Wx\Contracts;

interface UserProviderInterface
{
    /** @return array<string, mixed>|null */
    public function current(): ?array;

    /** @return array<string, mixed>|null */
    public function find(int|string $id): ?array;

    /** 账号总数；供统计层读取存量，不返回任何账号字段。 */
    public function count(): int;
}
