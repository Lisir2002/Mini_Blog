<?php
declare(strict_types=1);
namespace Wx\Systems\Attachment;

/**
 * 内存实现：拔掉数据库时的降级路径。
 * 元数据在进程内可见，字节仍落盘（或由调用方注入临时目录），因此上传 / 下载链路不因无库而中断。
 */
final class MemoryAttachmentRepository implements AttachmentRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    private static array $rows = [];
    private static int $seq = 0;

    public function add(array $file): int
    {
        $id = ++self::$seq;
        $row = $file;
        $row['id'] = $id;
        $row['post_slug'] = (string) ($file['post_slug'] ?? '');
        $row['sha256'] = (string) ($file['sha256'] ?? '');
        $row['bytes'] = (int) ($file['bytes'] ?? 0);
        $row['uploader_id'] = (int) ($file['uploader_id'] ?? 0);
        $row['visibility'] = (string) ($file['visibility'] ?? 'public');
        $row['created_at'] = (string) ($file['created_at'] ?? date('Y-m-d H:i:s'));
        $row['deleted_at'] = $file['deleted_at'] ?? null;
        self::$rows[$id] = $row;
        return $id;
    }

    public function find(int $id): ?array { return self::$rows[$id] ?? null; }

    public function findByHash(string $sha256): ?array
    {
        foreach (self::$rows as $row) {
            if ($row['sha256'] === $sha256 && $row['deleted_at'] === null) return $row;
        }
        return null;
    }

    public function listForPost(string $slug): array
    {
        $out = [];
        foreach (self::$rows as $row) {
            if ($row['post_slug'] === $slug && $row['deleted_at'] === null) $out[] = $row;
        }
        usort($out, static fn(array $a, array $b): int => $b['id'] <=> $a['id']);
        return $out;
    }

    public function update(int $id, array $fields): bool
    {
        if (!isset(self::$rows[$id])) return false;
        foreach (['post_slug', 'name', 'sha256', 'ext', 'mime', 'bytes',
            'visibility', 'uploader_id', 'created_at', 'deleted_at'] as $column) {
            if (array_key_exists($column, $fields)) self::$rows[$id][$column] = $fields[$column];
        }
        return true;
    }

    public function softDelete(int $id): bool
    {
        return $this->update($id, ['deleted_at' => date('Y-m-d H:i:s')]);
    }

    public function countForPost(string $slug): int
    {
        $count = 0;
        foreach (self::$rows as $row) {
            if ($row['post_slug'] === $slug && $row['deleted_at'] === null) $count++;
        }
        return $count;
    }

    public function referenced(string $sha256): int
    {
        $count = 0;
        foreach (self::$rows as $row) {
            if ($row['sha256'] === $sha256 && $row['deleted_at'] === null) $count++;
        }
        return $count;
    }

    /** 仅测试用。 */
    public static function reset(): void
    {
        self::$rows = [];
        self::$seq = 0;
    }
}
