<?php
declare(strict_types=1);
namespace Wx\Systems\Attachment;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\AbstractSystem;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;
use Wx\Systems\Database\Db;

/**
 * 附件系统 —— 第 16 个系统层。
 *
 * 把它做成独立一层，是因为「文件」与「文章」是两种生命周期的对象：
 * 文章可以删，文件可能被多篇文章、评论、表情同时引用。挂在内容层里，迟早变成
 * 「删文章顺手删了别人的头像」。系统层边界在这里就是数据所有权边界。
 */
final class AttachmentSystem extends AbstractSystem
{
    public function name(): string { return 'attachment'; }

    public function register(Kernel $kernel): void
    {
        $kernel->container()->singleton(
            AttachmentRepositoryInterface::class,
            static function () use ($kernel): AttachmentRepositoryInterface {
                $db = Db::optional($kernel);
                return $db === null ? new MemoryAttachmentRepository() : new PdoAttachmentRepository($db);
            },
            'system:attachment',
            10
        );
        $kernel->container()->singleton(
            AttachmentStorage::class,
            static fn(): AttachmentStorage => new AttachmentStorage($kernel->root() . '/storage/attachments'),
            'system:attachment:storage',
            10
        );
        $kernel->container()->singleton(
            AttachmentGuard::class,
            static fn(): AttachmentGuard => new AttachmentGuard(
                (int) $kernel->config()->get('attachment.max_bytes', 8388608)
            ),
            'system:attachment:guard',
            10
        );
    }

    public function boot(Kernel $kernel): void
    {
        /** @var AttachmentRepositoryInterface $repo */
        $repo = $kernel->container()->resolve(AttachmentRepositoryInterface::class);
        if ($repo instanceof PdoAttachmentRepository) $repo->ensureSchema();
        $this->ensureDir($kernel->root() . '/storage/attachments');

        /** @var AttachmentStorage $storage */
        $storage = $kernel->container()->resolve(AttachmentStorage::class);
        /** @var AttachmentGuard $guard */
        $guard = $kernel->container()->resolve(AttachmentGuard::class);
        $route = new AttachmentRoute($kernel);

        // priority=6：晚于 API（5）认领 /api/v1，早于独立页（10）与主题
        $kernel->hooks()->on('route.resolve', static function (Request $request, Response $response) use ($route): void {
            $route->handle($request, $response);
        }, 6);

        // 二进制响应不套主题：layout=binary 是「意图」，主题是否有该布局不影响判定。
        // 这是唯一允许绕开主题渲染的合法路径，且只对附件下载生效。
        $kernel->hooks()->filter('render.should_render',
            static fn(bool $should, Response $response): bool => $response->layout() === 'binary' ? false : $should, 5);

        if (!$kernel->container()->has(ApiRegistryInterface::class)) return;
        /** @var ApiRegistryInterface $registry */
        $registry = $kernel->container()->resolve(ApiRegistryInterface::class);
        $binary = new AttachmentBinary($repo, $storage, $guard, $kernel);
        $registry->registerOperation('attachments.upload', $binary->upload(...));
        $registry->registerOperation('attachments.download', $binary->download(...));
        (new AttachmentOperations($repo, $kernel))->register($registry);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('附件目录创建失败：' . $dir);
        }
        // 目录内禁止执行脚本：即使有人绕过类型白名单写入 .php，也不会有解释器去执行它。
        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "php_flag engine off\nOptions -ExecCGI -Indexes\n");
        }
    }
}
