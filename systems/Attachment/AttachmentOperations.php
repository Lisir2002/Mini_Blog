<?php
declare(strict_types=1);
namespace Wx\Systems\Attachment;

use InvalidArgumentException;
use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Kernel;
use Wx\Systems\Api\ApiOperationException;

/**
 * 附件元数据接口 —— 列表、改名 / 改可见性 / 改挂载文章、删除。
 *
 * 字节的上传与下载不在这里：那是二进制通道（AttachmentRoute）。
 * 这里所有操作都不碰文件系统，只改一行元数据，因此天然可审计、可回滚。
 */
final class AttachmentOperations
{
    public function __construct(
        private AttachmentRepositoryInterface $repo,
        private Kernel $kernel
    ) {}

    public function register(ApiRegistryInterface $registry): void
    {
        $registry->registerOperation('attachments.list', function (array $in): array {
            $slug = trim((string) ($in['post_slug'] ?? ''));
            if ($slug === '') throw new InvalidArgumentException('post_slug 必填');
            $identity = $in['_identity'] ?? null;
            $items = array_values(array_filter(
                $this->repo->listForPost($slug),
                fn(array $row): bool => $this->visible($identity, $row)
            ));
            return ['post_slug' => $slug, 'total' => count($items),
                'items' => array_map($this->present(...), $items)];
        });

        $registry->registerOperation('attachments.update', function (array $in): array {
            $row = $this->requireRow((int) ($in['id'] ?? 0));
            $identity = $in['_identity'] ?? null;
            if (!$this->canManage($identity, $row, 'attachment.edit.own')) {
                throw new ApiOperationException('无权修改该附件', 40303);
            }
            $changes = [];
            if (array_key_exists('name', $in) && trim((string) $in['name']) !== '') {
                $changes['name'] = AttachmentGuard::displayName((string) $in['name']);
            }
            if (array_key_exists('visibility', $in)) {
                $changes['visibility'] = ((string) $in['visibility']) === 'private' ? 'private' : 'public';
            }
            if (array_key_exists('post_slug', $in)) {
                $changes['post_slug'] = trim((string) $in['post_slug']);
            }
            if ($changes === []) throw new InvalidArgumentException('没有可更新的字段');
            $this->repo->update((int) $row['id'], $changes);
            $this->kernel->hooks()->emit('attachment.updated', (int) $row['id'], $changes, $identity);
            return ['id' => (int) $row['id'], 'updated' => array_keys($changes)];
        });

        $registry->registerOperation('attachments.delete', function (array $in): array {
            $row = $this->requireRow((int) ($in['id'] ?? 0));
            $identity = $in['_identity'] ?? null;
            if (!$this->canManage($identity, $row, 'attachment.delete.own')) {
                throw new ApiOperationException('无权删除该附件', 40303);
            }
            if (($row['deleted_at'] ?? null) !== null) {
                return ['id' => (int) $row['id'], 'deleted' => false, 'already' => true];
            }
            $this->repo->softDelete((int) $row['id']);
            // 字节不在这里删：可能仍有别的记录引用同一哈希，回收由引用计数为 0 的独立流程决定。
            $this->kernel->hooks()->emit('attachment.deleted', (int) $row['id'], $row, $identity);
            return ['id' => (int) $row['id'], 'deleted' => true, 'already' => false];
        });
    }

    /** @return array<string, mixed> */
    private function requireRow(int $id): array
    {
        if ($id <= 0) throw new InvalidArgumentException('id 必填且必须为正整数');
        $row = $this->repo->find($id);
        if ($row === null) throw new ApiOperationException('附件不存在', 40403);
        return $row;
    }

    private function visible(?array $identity, array $row): bool
    {
        if (($row['visibility'] ?? 'public') === 'public') return true;
        return $identity !== null && $this->canManage($identity, $row, 'attachment.read');
    }

    /** 先问「能否管理全部」，再问「能否管自己的」；两者都没有就拒绝。 */
    private function canManage(?array $identity, array $row, string $ownPermission): bool
    {
        if ($identity === null || $this->acl() === null) return false;
        $resource = ['type' => 'attachment', 'id' => (string) ($row['id'] ?? ''),
            'uploader_id' => (int) ($row['uploader_id'] ?? 0)];
        if ($this->acl()->allows($identity, 'attachment.manage', $resource)) return true;
        $owner = (int) ($row['uploader_id'] ?? 0) > 0
            && (int) ($row['uploader_id'] ?? 0) === (int) ($identity['user_id'] ?? 0);
        return $owner && $this->acl()->allows($identity, $ownPermission, $resource);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function present(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'name' => (string) $row['name'],
            'mime' => (string) $row['mime'], 'ext' => (string) $row['ext'],
            'bytes' => (int) $row['bytes'], 'sha256' => (string) $row['sha256'],
            'visibility' => (string) ($row['visibility'] ?? 'public'),
            'post_slug' => (string) ($row['post_slug'] ?? ''),
            'uploader_id' => (int) ($row['uploader_id'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'url' => '/attach/download?id=' . (int) $row['id'],
        ];
    }

    private function acl(): ?AccessControlInterface
    {
        $container = $this->kernel->container();
        return $container->has(AccessControlInterface::class)
            ? $container->resolve(AccessControlInterface::class) : null;
    }
}
