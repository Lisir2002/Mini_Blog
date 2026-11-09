<?php
declare(strict_types=1);
namespace Wx\Systems\Comment;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Contracts\CommentStoreInterface;
use Wx\Core\AbstractSystem;
use Wx\Core\Kernel;
use Wx\Systems\Database\Db;

/**
 * 评论系统：只负责评论数据的存取与业务钩子。
 * 评论区的 HTML 结构、样式、交互全部由主题渲染 —— 系统层不输出一个标签。
 * 接上 MySQL 走 PdoCommentStore，拔掉库回退内存适配器。
 */
final class CommentSystem extends AbstractSystem
{
    public function name(): string { return 'comment'; }

    public function register(Kernel $kernel): void
    {
        $kernel->container()->singleton(
            CommentStoreInterface::class,
            static function () use ($kernel): CommentStoreInterface {
                $db = Db::optional($kernel);
                return $db === null
                    ? new MemoryCommentStore()
                    : new PdoCommentStore($db);
            },
            'system:comment',
            10
        );

        $kernel->hooks()->filter('comment.before_save', static function (array $comment): array {
            $comment['created_at'] = $comment['created_at'] ?? date('Y-m-d H:i:s');
            $comment['status']     = $comment['status'] ?? 'pending';
            return $comment;
        }, 10);
    }

    public function boot(Kernel $kernel): void
    {
        $store = $kernel->container()->resolve(CommentStoreInterface::class);
        if ($store instanceof PdoCommentStore) $store->ensureSchema();
        if (!$kernel->container()->has(ApiRegistryInterface::class)) return;

        /** @var ApiRegistryInterface $registry */
        $registry = $kernel->container()->resolve(ApiRegistryInterface::class);
        $registry->registerOperation('comments.list', static function (array $in) use ($store): array {
            $slug  = (string) ($in['post_slug'] ?? $in['slug'] ?? '');
            $items = $store->forPost($slug);
            return ['post_slug' => $slug, 'items' => $items, 'total' => count($items)];
        });
        $registry->registerOperation('comments.add', static function (array $in) use ($store, $kernel): array {
            $comment = (array) $kernel->hooks()->apply('comment.before_save', [
                'post_slug' => (string) ($in['post_slug'] ?? $in['slug'] ?? ''),
                'author'    => (string) ($in['author'] ?? ''),
                'body'      => (string) ($in['body'] ?? ''),
            ]);
            if (trim((string) $comment['body']) === '') {
                throw new \InvalidArgumentException('评论内容不能为空');
            }
            $id = $store->add($comment);
            // 与 content.created 同一模式：评论层广播事实，订阅者自行决定是否发经验 / 通知。
            $kernel->hooks()->emit('comment.created', $id, $comment, $in['_identity'] ?? null);
            return ['id' => $id, 'status' => (string) $comment['status'], 'created' => true];
        });
    }
}
