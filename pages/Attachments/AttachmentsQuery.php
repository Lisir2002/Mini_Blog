<?php
declare(strict_types=1);
namespace Wx\Pages\Attachments;

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Kernel;
use Wx\Systems\Api\ApiOperationException;

/**
 * 附件库取数 —— 先选文章，再按文章列附件。
 *
 * `attachments.list` 的 post_slug 是必填的：没有「列出全站附件」的接口，
 * 所以后台必须先有「选中某篇文章」的路径，否则任何一版都做不出附件库。
 * 文章清单来自 content.posts（status=any，不含回收站）。
 *
 * 两个容易说错的地方：
 *  1. 列表按**当前身份**取（传 _identity），否则私有附件会被 visible() 过滤掉，
 *     站长会以为「这个附件不见了」；文章页刻意传 null，那是公开读路径，不照抄；
 *  2. 文章、正文、附件三件事各自独立成败，读不到正文 ≠ 没有引用 —— 后者
 *     必须让删除动作 fail-closed，而不是当作「可以删」。
 */
final class AttachmentsQuery
{
    public const POST_LIMIT = 20;

    /**
     * @param array<string, mixed>|null $identity
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public static function fetch(Kernel $kernel, ?array $identity, array $query): array
    {
        $slug = trim((string) ($query['slug'] ?? ''));
        $rawId = trim((string) ($query['id'] ?? ''));
        if ($slug !== '' && preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/', $slug) !== 1) {
            return self::fail(422, '文章 slug 只能用小写字母、数字、连字符（最长 100）。');
        }
        if ($rawId !== '' && preg_match('/^\d+$/', $rawId) !== 1) {
            return self::fail(422, '附件 id 必须是正整数。');
        }

        $out = ['ok' => true, 'status' => 200, 'error' => '', 'slug' => $slug,
            'id' => $rawId === '' ? 0 : (int) $rawId,
            'posts' => null, 'posts_fail' => '', 'post' => null,
            'post_state' => 'idle', 'post_error' => '',
            'items' => null, 'total' => 0, 'attachments_state' => 'idle', 'attachments_error' => '',
            'refs' => [], 'can' => self::can($kernel, $identity)];

        $container = $kernel->container();
        if (!$container->has(ApiRegistryInterface::class)) {
            return self::fail(503, '接口清单未装配，附件数据无法读取。');
        }
        $registry = $container->resolve(ApiRegistryInterface::class);
        if ($slug === '') {
            try {
                $r = $registry->execute('content.posts', ['status' => 'any', 'limit' => self::POST_LIMIT]);
                $out['posts'] = array_values(array_filter((array) ($r['items'] ?? []), 'is_array'));
            } catch (\Throwable $e) {
                $out['posts_fail'] = $e->getMessage();
            }
            return $out;
        }
        self::article($registry, $slug, $identity, $out);
        self::attachments($registry, $slug, $identity, $out);
        return $out;
    }

    /** @param array<string, mixed> $out */
    private static function article(ApiRegistryInterface $registry, string $slug,
        ?array $identity, array &$out): void
    {
        try {
            $out['post'] = $registry->execute('content.post', ['slug' => $slug, '_identity' => $identity]);
            $out['post_state'] = 'found';
        } catch (ApiOperationException $e) {
            $out['post_state'] = $e->apiCode === 40403 ? 'missing' : 'failed';
            $out['post_error'] = $e->getMessage();
        } catch (\Throwable $e) {
            $out['post_state'] = 'failed';
            $out['post_error'] = $e->getMessage();
        }
    }

    /** @param array<string, mixed> $out */
    private static function attachments(ApiRegistryInterface $registry, string $slug,
        ?array $identity, array &$out): void
    {
        try {
            $r = $registry->execute('attachments.list', ['post_slug' => $slug, '_identity' => $identity]);
            $out['items'] = array_values(array_filter((array) ($r['items'] ?? []), 'is_array'));
            $out['total'] = (int) ($r['total'] ?? count($out['items']));
            $out['attachments_state'] = $out['items'] === [] ? 'empty' : 'ok';
        } catch (\Throwable $e) {
            $out['attachments_state'] = 'unavailable';
            $out['attachments_error'] = $e->getMessage();
        }
        if ($out['post_state'] === 'found') {
            $body = (string) ($out['post']['body'] ?? '');
            $out['refs'] = AttachmentsRefs::scan($body, (array) $out['items']);
        } else {
            $out['refs'] = AttachmentsRefs::unknown((array) $out['items']);
        }
    }

    /** @param array<string, mixed>|null $identity */
    private static function can(Kernel $kernel, ?array $identity): bool
    {
        $container = $kernel->container();
        if (!$container->has(AccessControlInterface::class)) return false;
        $acl = $container->resolve(AccessControlInterface::class);
        return $acl instanceof AccessControlInterface
            && $acl->allows($identity, 'attachment.manage');
    }

    /** @return array<string, mixed> */
    private static function fail(int $status, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'error' => $message, 'slug' => '', 'id' => 0,
            'posts' => null, 'posts_fail' => '', 'post' => null, 'post_state' => 'idle',
            'post_error' => '', 'items' => null, 'total' => 0, 'attachments_state' => 'idle',
            'attachments_error' => '', 'refs' => [], 'can' => false];
    }
}
