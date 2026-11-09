<?php
declare(strict_types=1);
namespace Wx\Systems\Release;

/**
 * 迁移台账的读写协议 —— 只回答「哪些脚本执行过」，不负责执行 SQL。
 * 实现可换（MySQL / 内存 / 未来的远程元数据服务），执行逻辑不必知道。
 */
interface MigrationStoreInterface
{
    /** @return list<array{id: string, owner: string, checksum: string, batch: int, applied_at: string}> */
    public function all(): array;

    /** @return array{id: string, owner: string, checksum: string, batch: int, applied_at: string}|null */
    public function find(string $id): ?array;

    public function record(string $id, string $owner, string $checksum, int $batch): void;

    public function forget(string $id): void;

    /** 下一批次号：同一轮升级共享一个批次，回退时按批次成组撤销。 */
    public function nextBatch(): int;
}
