<?php
declare(strict_types=1);
namespace Wx\Systems\Comment;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Contracts\CommentStoreInterface;
use Wx\Core\AbstractSystem;
use Wx\Core\Kernel;
use Wx\Systems\Data\Db;
use Wx\Systems\Data\RelationRegistry;

/**
 * 评论系统：评论数据的存取、树形规则与业务钩子。
 *
 * 边界仍是「系统层不输出一个标签」：评论区的 HTML、样式、交互全部由主题渲染。
 * 接上 MySQL 走 PdoCommentStore，拔掉库回退内存适配器 —— 删光数据库也能启动。
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
                return $db === null ? new MemoryCommentStore() : new PdoCommentStore($db);
            },
            'system:comment',
            10
        );

        // 默认值只在这里补一次：任何写入路径（接口、插件、导入脚本）都经过同一钩子，
        // 不会出现「接口写的是 pending、导入脚本写的是 published」两套默认。
        $autoPublish = (bool) $kernel->config()->get('comment.auto_publish', false);
        $kernel->hooks()->filter('comment.before_save', static function (array $comment) use ($autoPublish): array {
            $comment['created_at'] = $comment['created_at'] ?? date('Y-m-d H:i:s');
            $comment['status']     = $comment['status'] ?? ($autoPublish ? CommentPolicy::PUBLISHED : CommentPolicy::PENDING);
            $comment['parent_id']  = (int) ($comment['parent_id'] ?? CommentTree::ROOT);
            $comment['depth']      = (int) ($comment['depth'] ?? 0);
            $comment['author_id']  = (int) ($comment['author_id'] ?? 0);
            $comment['author']     = trim((string) ($comment['author'] ?? '')) ?: ($comment['author_id'] > 0 ? '成员' : '匿名访客');
            return $comment;
        }, 10);
    }

    public function boot(Kernel $kernel): void
    {
        /** @var CommentStoreInterface $store */
        $store = $kernel->container()->resolve(CommentStoreInterface::class);
        if ($store instanceof PdoCommentStore) $store->ensureSchema();

        // 关系由表的归属系统登记：data 层只负责执行孤儿巡检，不知道「评论」是什么。
        if ($kernel->container()->has(RelationRegistry::class)) {
            $relations = $kernel->container()->resolve(RelationRegistry::class);
            $relations->declare('comment.parent', 'comments', 'parent_id', 'comments', 'id', true);
            $relations->declare('comment.root', 'comments', 'root_id', 'comments', 'id', true);
        }

        // 评论存量按状态自报：统计层只拿到 {published: 12, pending: 3}，看不到任何正文。
        $kernel->hooks()->filter('metric.collect', static function (array $samples) use ($store): array {
            foreach ($store->counts() as $status => $count) {
                $samples[] = ['name' => 'comment_total', 'labels' => ['status' => $status], 'value' => $count];
            }
            return $samples;
        }, 10);

        if (!$kernel->container()->has(ApiRegistryInterface::class)) return;

        $guard = new CommentGuard(
            (int) $kernel->config()->get('comment.max_length', 5000),
            (int) $kernel->config()->get('comment.max_links', 3)
        );
        /** @var ApiRegistryInterface $registry */
        $registry = $kernel->container()->resolve(ApiRegistryInterface::class);
        (new CommentOperations($store, new CommentWriter($store, $guard, $kernel), $kernel))
            ->register($registry);
    }
}
