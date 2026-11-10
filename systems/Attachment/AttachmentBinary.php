<?php
declare(strict_types=1);
namespace Wx\Systems\Attachment;

use InvalidArgumentException;
use Wx\Contracts\AccessControlInterface;
use Wx\Core\Kernel;
use Wx\Systems\Api\ApiOperationException;

/**
 * 附件二进制的业务执行者 —— 上传与下载的「事实」，不含任何 HTTP 细节。
 *
 * 与 AttachmentRoute 的分工：这里决定「能不能收、收到哪、能不能给」，
 * Route 只负责把结果写成响应。这样二进制接口与 JSON 接口一样，
 * 在协作图里都有一个明确的执行者，不会成为「登记了但没人实现」的孤儿。
 */
final class AttachmentBinary
{
    public function __construct(
        private AttachmentRepositoryInterface $repo,
        private AttachmentStorage $storage,
        private AttachmentGuard $guard,
        private Kernel $kernel
    ) {}

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public function upload(array $in): array
    {
        $content = (string) ($in['_content'] ?? '');
        $file = $this->guard->inspect((string) ($in['name'] ?? ''), $content);

        $slug = trim((string) ($in['post_slug'] ?? ''));
        $maxPerPost = (int) $this->kernel->config()->get('attachment.max_per_post', 20);
        if ($slug !== '' && $this->repo->countForPost($slug) >= $maxPerPost) {
            throw new InvalidArgumentException("单篇文章附件数已达 {$maxPerPost} 个上限");
        }
        if (!$this->storage->exists($file['sha256'])) {
            $this->storage->put($content, $file['sha256']);
        }

        $id = $this->repo->add([
            'post_slug'   => $slug,
            'name'        => $file['name'],
            'sha256'      => $file['sha256'],
            'ext'         => $file['ext'],
            'mime'        => $file['mime'],
            'bytes'       => $file['bytes'],
            'visibility'  => ((string) ($in['visibility'] ?? 'public')) === 'private' ? 'private' : 'public',
            'uploader_id' => (int) ($in['_identity']['user_id'] ?? 0),
        ]);
        $this->kernel->hooks()->emit('attachment.uploaded', $id, $file, $in['_identity'] ?? null);

        return [
            'id' => $id, 'name' => $file['name'], 'mime' => $file['mime'],
            'bytes' => $file['bytes'], 'sha256' => $file['sha256'],
            'url' => '/attach/download?id=' . $id,
        ];
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public function download(array $in): array
    {
        $row = $this->repo->find((int) ($in['id'] ?? 0));
        if ($row === null || ($row['deleted_at'] ?? null) !== null) {
            throw new ApiOperationException('附件不存在', 40403);
        }
        // 私有附件必须过授权：不可猜的 id 不是访问控制，只是模糊化。
        if (((string) ($row['visibility'] ?? 'public')) === 'private') {
            $this->assertReadable($in['_identity'] ?? null, $row);
        }

        $sha  = (string) $row['sha256'];
        $size = $this->storage->size($sha);
        if ($size === null) throw new ApiOperationException('附件字节缺失', 50001);

        $meta = [
            'name'       => (string) $row['name'],
            'mime'       => (string) $row['mime'],
            'visibility' => (string) ($row['visibility'] ?? 'public'),
            'inline'     => ((string) ($in['disposition'] ?? 'attachment')) === 'inline'
                && str_starts_with((string) $row['mime'], 'image/'),
            'size'       => $size,
        ];

        // 大文件不读进内存：交给 Route 分块输出。阈值 <= 0 时一律按字节返回（旧行为）。
        $threshold = (int) $this->kernel->config()->get('attachment.stream_threshold', 1048576);
        if ($threshold > 0 && $size >= $threshold) {
            $path = $this->storage->path($sha);
            if ($path !== null) return $meta + ['path' => $path];
        }

        $bytes = $this->storage->read($sha);
        if ($bytes === null) throw new ApiOperationException('附件字节缺失', 50001);
        return $meta + ['bytes' => $bytes];
    }

    private function assertReadable(?array $identity, array $row): void
    {
        if ($identity === null) throw new ApiOperationException('需要登录后下载私有附件', 40101);
        $owner = (int) ($row['uploader_id'] ?? 0) > 0
            && (int) ($row['uploader_id'] ?? 0) === (int) ($identity['user_id'] ?? 0);
        if ($owner) return;
        $container = $this->kernel->container();
        $acl = $container->has(AccessControlInterface::class)
            ? $container->resolve(AccessControlInterface::class) : null;
        if ($acl === null || !$acl->allows($identity, 'attachment.read')) {
            throw new ApiOperationException('无权下载该附件', 40303);
        }
    }
}
