<?php
declare(strict_types=1);
namespace Wx\Pages\Terms;

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Kernel;
use Wx\Systems\Api\ApiOperationException;

/**
 * 分类与标签页取数 —— 清单、文章窗口、当前文章三份，各自降级。
 *
 * 三条约束决定页面形态：
 *  1. `content.terms` 在内存降级模式返回 50101，不是空数组。写「还没有分类」会把
 *     「功能不可用」说成「数据为空」，两者必须分开；
 *  2. 术语没有按资源过滤的接口，但 `content.post` / `content.posts` 实际会带 terms
 *     字段（v0.45 起写进契约 response_fields）。页面靠它预填「这篇文章已挂了什么」；
 *  3. `content.posts(status=any)` 只给前 20 篇，且 `content.post` 对回收站 / 未到点
 *     的定时文章返回 40403。读不到文章时不能把「不知道」渲染成「没有术语」。
 */
final class TermsQuery
{
    public const POST_LIMIT = 20;
    public const TERM_LIMIT = 60;

    /**
     * @param array<string, mixed>|null $identity
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public static function fetch(Kernel $kernel, ?array $identity, array $query): array
    {
        $slug = trim((string) ($query['slug'] ?? ''));
        $kind = trim((string) ($query['kind'] ?? 'any'));
        if (!in_array($kind, ['any', 'category', 'tag'], true)) {
            return self::fail(422, 'kind 只能是 any / category / tag。');
        }
        if ($slug !== '' && preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/', $slug) !== 1) {
            return self::fail(422, '文章 slug 只能用小写字母、数字、连字符。');
        }

        $out = ['ok' => true, 'status' => 200, 'error' => '', 'slug' => $slug, 'kind' => $kind,
            'terms' => [], 'terms_state' => 'idle', 'terms_fail' => '',
            'terms_total' => 0, 'terms_shown' => 0, 'terms_truncated' => false,
            'posts' => [], 'posts_fail' => '', 'posts_total' => 0, 'posts_truncated' => false,
            'post' => null, 'post_state' => 'idle', 'post_error' => '',
            'attached' => [], 'attached_state' => 'idle',
            'can_terms' => self::can($kernel, $identity, 'content.manage_terms'),
            'can_edit' => self::can($kernel, $identity, 'content.edit')];

        $container = $kernel->container();
        if (!$container->has(ApiRegistryInterface::class)) {
            return self::fail(503, '接口清单未装配，分类与标签数据无法读取。');
        }
        $registry = $container->resolve(ApiRegistryInterface::class);
        self::terms($registry, $kind, $out);
        self::posts($registry, $out);
        self::post($registry, $slug, $out);
        return $out;
    }

    /** @param array<string, mixed> $out */
    private static function terms(ApiRegistryInterface $registry, string $kind, array &$out): void
    {
        try {
            $r = $registry->execute('content.terms', ['kind' => $kind]);
            $items = array_values(array_filter((array) ($r['items'] ?? []), 'is_array'));
            $out['terms_total'] = count($items);
            $out['terms'] = array_slice($items, 0, self::TERM_LIMIT);
            $out['terms_shown'] = count($out['terms']);
            $out['terms_truncated'] = $out['terms_total'] > $out['terms_shown'];
            $out['terms_state'] = $items === [] ? 'empty' : 'ok';
        } catch (ApiOperationException $e) {
            $out['terms_state'] = $e->apiCode === 50101 ? 'unavailable' : 'failed';
            $out['terms_fail'] = $e->getMessage();
        } catch (\Throwable $e) {
            $out['terms_state'] = 'failed';
            $out['terms_fail'] = $e->getMessage();
        }
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

    /** @param array<string, mixed> $out */
    private static function post(ApiRegistryInterface $registry, string $slug, array &$out): void
    {
        if ($slug === '') return;
        try {
            $post = $registry->execute('content.post', ['slug' => $slug]);
            $out['post'] = $post;
            $out['post_state'] = 'found';
            // 契约里没有 terms 字段时不能说「没有术语」—— 那是「不知道」。
            if (!array_key_exists('terms', $post)) {
                $out['attached_state'] = 'unknown';
                return;
            }
            $out['attached'] = array_values(array_filter((array) $post['terms'], 'is_array'));
            $out['attached_state'] = $out['attached'] === [] ? 'empty' : 'ok';
        } catch (ApiOperationException $e) {
            $out['post_state'] = $e->apiCode === 40403 ? 'missing' : 'failed';
            $out['post_error'] = $e->getMessage();
        } catch (\Throwable $e) {
            $out['post_state'] = 'failed';
            $out['post_error'] = $e->getMessage();
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
    private static function fail(int $status, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'error' => $message,
            'slug' => '', 'kind' => 'any', 'terms' => [], 'terms_state' => 'idle', 'terms_fail' => '',
            'terms_total' => 0, 'terms_shown' => 0, 'terms_truncated' => false,
            'posts' => [], 'posts_fail' => '', 'posts_total' => 0, 'posts_truncated' => false,
            'post' => null, 'post_state' => 'idle', 'post_error' => '',
            'attached' => [], 'attached_state' => 'idle',
            'can_terms' => false, 'can_edit' => false];
    }
}
