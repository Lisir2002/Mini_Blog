<?php
declare(strict_types=1);
namespace Wx\Contracts;

interface UserProviderInterface
{
    /** @return array<string, mixed>|null */
    public function current(): ?array;

    /** @return array<string, mixed>|null */
    public function find(int|string $id): ?array;
}
