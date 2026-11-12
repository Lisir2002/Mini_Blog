<?php
declare(strict_types=1);
namespace Wx\Pages\Queue;

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Kernel;
use Wx\Systems\Api\ApiOperationException;

/**
 * 审核队列取数 —— 队列视图与「按文章浏览」，两条读路径各自降级。
 *
 * 三条约束决定页面形态：
 *  1. comments.queue 的口径是 pending + 有举报，不含已发布的普通评论，
 *     所以要删任意一条评论必须另开 content.posts → comments.list 的浏览路径；
 *  2. comments.list 需要显式 _identity 才看得到 hidden / spam / deleted 占位。
 *     页面先用 content.post 复核文章存在，避免把「文章不存在」说成「没有评论」；
 *  3. 删除动作现场问 comment.delete.any，不用接口返回的 deletable：后者还含
 *     「本人 + comment.delete.own」，那是前台删自己评论的入口，审核页不悄悄开第二条路。
 */
final class QueueQuery
{
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT = 100;
    public const POST_LIMIT = 20;
    public const COMMENT_LIMIT = 60;
    public const VIEWS = ['queue', 'post'];

    /** @param array<string, mixed>|null $identity @param array<string, mixed> $query
     *  @return array<string, mixed> */
    public static function fetch(Kernel $kernel, ?array $identity, array $query): array
    {
        $view = in_array((string) ($query['view'] ?? ''), self::VIEWS, true)
            ? (string) $query['view'] : 'queue';
        $slug = trim((string) ($query['slug'] ?? ''));
        if ($slug !== '' && preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/', $slug) !== 1) {
            return self::fail(422, '文章 slug 只能用小写字母、数字、连字符。', $view);
        }
        $out = self::blank($view, $slug);
        $out['can_delete']   = self::can($kernel, $identity, 'comment.delete.any');
        $out['can_moderate'] = self::can($kernel, $identity, 'comment.moderate');

        $container = $kernel->container();
        if (!$container->has(ApiRegistryInterface::class)) {
            return self::fail(503, '接口清单未装配，评论数据无法读取。', $view);
        }
        $registry = $container->resolve(ApiRegistryInterface::class);
        self::posts($registry, $out);
        if ($view === 'queue') {
            self::queue($registry, $query, $out);
            return $out;
        }
        self::comments($registry, $identity, $slug, $out);
        return $out;
    }

    /** @return array<string, mixed> */
    private static function blank(string $view, string $slug): array
    {
        return ['ok' => true, 'status' => 200, 'error' => '', 'view' => $view, 'slug' => $slug,
            'can_delete' => false, 'can_moderate' => false,
            'posts' => [], 'posts_fail' => '', 'posts_total' => 0, 'posts_truncated' => false,
            'queue' => [], 'queue_state' => 'idle', 'queue_fail' => '',
            'queue_total' => 0, 'queue_shown' => 0, 'queue_truncated' => false,
            'comments' => [], 'comments_state' => 'idle', 'comments_fail' => '',
            'comments_total' => 0, 'comments_shown' => 0, 'comments_truncated' => false,
            'post_state' => 'idle', 'post_error' => ''];
    }

    /** @param array<string, mixed> $out */
    private static function posts(ApiRegistryInterface $registry, array &$out): void
    {
        try {
            $r = $registry->execute('content.posts', ['status' => 'any', 'limit' => self::POST_LIMIT]);
            $out['posts'] = array_values(array_filter((array) ($r['items'] ?? []), 'is_array'));
            $out['posts_total'] = (int) ($r['total'] ?? count($out['posts']));
            $out['posts_truncated'] = $out['posts_total'] > count($out['posts']);
        } catch (\Throwable $e) {
            $out['posts_fail'] = $e->getMessage();
        }
    }

    /** @param array<string, mixed> $query @param array<string, mixed> $out */
    private static function queue(ApiRegistryInterface $registry, array $query, array &$out): void
    {
        $limit  = max(1, min(self::MAX_LIMIT, (int) ($query['limit'] ?? self::DEFAULT_LIMIT)));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        try {
            $r = $registry->execute('comments.queue', ['limit' => $limit, 'offset' => $offset]);
            $items = array_values(array_filter((array) ($r['items'] ?? []), 'is_array'));
            $out['queue'] = $items;
            $out['queue_shown'] = count($items);
            $out['queue_total'] = (int) ($r['total'] ?? count($items));
            $out['queue_truncated'] = (bool) ($r['truncated'] ?? false)
                || $offset + count($items) < $out['queue_total'];
            $out['queue_state'] = $out['queue_total'] === 0 ? 'empty' : 'ok';
        } catch (\Throwable $e) {
            $out['queue_state'] = 'failed';
            $out['queue_fail'] = $e->getMessage();
        }
    }

    /** @param array<string, mixed>|null $identity @param array<string, mixed> $out */
    private static function comments(ApiRegistryInterface $registry, ?array $identity,
        string $slug, array &$out): void
    {
        if ($slug === '') {
            $out['post_state'] = 'none';
            $out['comments_state'] = 'no-post';
            return;
        }
        try {
            $registry->execute('content.post', ['slug' => $slug]);
            $out['post_state'] = 'found';
        } catch (ApiOperationException $e) {
            $out['post_state'] = $e->apiCode === 40403 ? 'missing' : 'failed';
            $out['post_error'] = $e->getMessage();
            $out['comments_state'] = 'no-post';
            return; // fail-closed：文章读不到，绝不把「不知道」渲染成「没有评论」
        } catch (\Throwable $e) {
            $out['post_state'] = 'failed';
            $out['post_error'] = $e->getMessage();
            $out['comments_state'] = 'no-post';
            return;
        }
        try {
            $r = $registry->execute('comments.list', ['post_slug' => $slug, '_identity' => $identity]);
            $items = array_values(array_filter((array) ($r['items'] ?? []), 'is_array'));
            $out['comments_total'] = (int) ($r['total'] ?? count($items));
            $out['comments'] = array_slice($items, 0, self::COMMENT_LIMIT);
            $out['comments_shown'] = count($out['comments']);
            $out['comments_truncated'] = (bool) ($r['truncated'] ?? false)
                || $out['comments_total'] > $out['comments_shown'];
            $out['comments_state'] = $items === [] ? 'empty' : 'ok';
        } catch (\Throwable $e) {
            $out['comments_state'] = 'failed';
            $out['comments_fail'] = $e->getMessage();
        }
    }

    /** @param array<string, mixed>|null $identity */
    private static function can(Kernel $kernel, ?array $identity, string $permission): bool
    {
        $container = $kernel->container();
        if (!$container->has(AccessControlInterface::class)) return false;
        $acl = $container->resolve(AccessControlInterface::class);
        return $acl instanceof AccessControlInterface && $acl->allows($identity, $permission);
    }

    /** @return array<string, mixed> */
    private static function fail(int $status, string $message, string $view): array
    {
        $out = self::blank($view, '');
        $out['ok'] = false;
        $out['status'] = $status;
        $out['error'] = $message;
        return $out;
    }
}
