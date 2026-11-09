<?php
declare(strict_types=1);
namespace Wx\Systems\Attachment;

/**
 * 附件仓储契约 —— 只描述「一份附件的元数据」，不描述字节怎么存。
 *
 * 元数据与字节刻意分开：元数据可以被查询、授权、审计、软删；
 * 字节按内容哈希存放，可能被多条记录共享。把两者塞进一行会让「删一条记录」变成「删一个文件」，
 * 一处误删就影响所有引用者。
 */
interface AttachmentRepositoryInterface
{
    /** @param array<string, mixed> $file @return int 附件 ID */
    public function add(array $file): int;

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array;

    /** 内容寻址去重：同一份字节只存一次，元数据仍各自独立 */
    public function findByHash(string $sha256): ?array;

    /** @return list<array<string, mixed>> 未删除的附件，按 id 倒序 */
    public function listForPost(string $slug): array;

    /** @param array<string, mixed> $fields 只接受白名单列 @return bool 是否命中一行 */
    public function update(int $id, array $fields): bool;

    /** 软删：保留行与字节引用，便于审计与恢复 */
    public function softDelete(int $id): bool;

    /** 该文章下未删除的附件数，用于配额判定 */
    public function countForPost(string $slug): int;

    /** 同一份字节当前被多少条未删除记录引用 */
    public function referenced(string $sha256): int;
}
