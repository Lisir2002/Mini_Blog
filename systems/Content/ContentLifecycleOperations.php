<?php
declare(strict_types=1);
namespace Wx\Systems\Content;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Contracts\ContentRepositoryInterface;
use Wx\Core\Kernel;
use Wx\Systems\Api\ApiOperationException;

/**
 * 内容生命周期接口 —— 状态迁移、回收站、彻底删除。
 *
 * 单独成文件而不是塞回 ContentSystem：那里已经承担读路径、字段级写权限与表情呈现，
 * 再加生命周期会让「一次改动影响几个互不相干的场景」。
 *
 * 三个动作的语义边界写死在这里：
 *   - 软删除只打 deleted_at，状态原样保留（回收站里的草稿还是草稿）；
 *   - 恢复只清 deleted_at，不顺手把文章改成已发布 —— 那会把「删错了」变成「偷偷上线」；
 *   - 彻底删除只对回收站里的文章生效，绕过回收站直接抹数据必须走两步，
 *     目的是让「手滑」与「决定」之间隔一个动作。
 */
final class ContentLifecycleOperations
{
    public function __construct(private ContentRepositoryInterface $repo) {}

    public function role(): string { return 'content_lifecycle_operations'; }

    public function register(ApiRegistryInterface $registry, Kernel $kernel): void
    {
        $repo = $this->repo;

        $registry->registerOperation('content.transition', static function (array $in) use ($repo, $kernel): array {
            $slug = trim((string) ($in['slug'] ?? ''));
            if ($slug === '') throw new ApiOperationException('slug 必填', 42201);
            $to = trim((string) ($in['to'] ?? ''));
            $post = $repo->findBySlug($slug);
            if ($post === null) {
                // 回收站里的文章既不该被「发布」，也不该报「不存在」——后者会让人以为删错了。
                $inTrash = $repo->findBySlug($slug, true) !== null;
                throw new ApiOperationException(
                    $inTrash ? '文章在回收站，请先恢复' : '文章不存在',
                    $inTrash ? 40909 : 40403
                );
            }
            ContentStatus::assertKnown($to);
            $from = (string) $post['status'];
            if (!ContentStatus::canTransition($from, $to)) {
                throw new ApiOperationException(
                    '不允许的状态迁移：' . $from . ' → ' . $to . '（可达：' . implode(' / ', ContentStatus::nextOf($from)) . '）',
                    40908
                );
            }

            // 定时发布只对 published 有意义。改成草稿却留着未来的 publish_at，
            // 下次有人把它发布出去就会莫名其妙「还没上线」，这种残留在库里必须清掉。
            $publishAt = $to === ContentStatus::PUBLISHED ? self::time($in['publish_at'] ?? null) : null;
            if (!$repo->transition($slug, $from, $to, $publishAt)) {
                // 比较并设置失败：两次点击之间有人改过状态。不能假装成功。
                throw new ApiOperationException('文章状态已被其他操作修改，请刷新后重试', 40908);
            }
            $scheduled = $publishAt !== null && strtotime($publishAt) > time();
            $kernel->hooks()->emit('content.transitioned', $slug, $from, $to, $publishAt, $in['_identity'] ?? null);
            return [
                'slug'       => $slug,
                'from'       => $from,
                'to'         => $to,
                'publish_at' => $publishAt,
                'scheduled'  => $scheduled,
            ];
        });

        $registry->registerOperation('content.delete', static function (array $in) use ($repo, $kernel): array {
            $slug = self::slug($in);
            if (!$repo->delete($slug)) {
                $exists = $repo->findBySlug($slug, true) !== null;
                throw new ApiOperationException($exists ? '文章已在回收站' : '文章不存在', $exists ? 40909 : 40403);
            }
            $kernel->hooks()->emit('content.deleted', $slug, $in['_identity'] ?? null);
            return ['slug' => $slug, 'deleted' => true, 'restorable' => true];
        });

        $registry->registerOperation('content.restore', static function (array $in) use ($repo, $kernel): array {
            $slug = self::slug($in);
            if (!$repo->restore($slug)) {
                $exists = $repo->findBySlug($slug, true) !== null;
                throw new ApiOperationException($exists ? '文章不在回收站' : '文章不存在', $exists ? 40910 : 40403);
            }
            $post = $repo->findBySlug($slug);
            $kernel->hooks()->emit('content.restored', $slug, $in['_identity'] ?? null);
            return ['slug' => $slug, 'restored' => true, 'status' => (string) ($post['status'] ?? '')];
        });

        $registry->registerOperation('content.purge', static function (array $in) use ($repo, $kernel): array {
            $slug = self::slug($in);
            // 二次确认用「再写一遍 slug」而不是 confirm=true：布尔值会被前端默认填上，
            // 写 slug 是没法顺手点过去的动作，代价刚好等于这件事的不可逆程度。
            if (trim((string) ($in['confirm'] ?? '')) !== $slug) {
                throw new ApiOperationException('彻底删除需要 confirm 与 slug 完全一致', 42201);
            }
            if (!$repo->purge($slug)) {
                throw new ApiOperationException('只有回收站里的文章才能彻底删除', 40910);
            }
            $kernel->hooks()->emit('content.purged', $slug, $in['_identity'] ?? null);
            return ['slug' => $slug, 'purged' => true];
        });

        $registry->registerOperation('content.trash', static function (array $in) use ($repo): array {
            return $repo->trash([
                'limit'   => (int)    ($in['limit']  ?? 20),
                'cursor'  => (string) ($in['cursor'] ?? ''),
                'keyword' => (string) ($in['keyword'] ?? ''),
            ]);
        });
    }

    /** @param array<string, mixed> $in */
    private static function slug(array $in): string
    {
        $slug = trim((string) ($in['slug'] ?? ''));
        if ($slug === '') throw new ApiOperationException('slug 必填', 42201);
        return $slug;
    }

    /**
     * 解析定时时间。接受任意 strtotime 能读懂的写法，统一存成 DATETIME。
     * 解析失败必须报错：静默当成 null 会让「定时发布」变成「立即发布」，
     * 而调用方以为自己排好了。
     */
    private static function time(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') return null;
        if (!is_string($raw) && !is_int($raw)) throw new ApiOperationException('publish_at 必须是时间字符串', 42201);
        $ts = strtotime((string) $raw);
        if ($ts === false) throw new ApiOperationException('publish_at 无法解析：' . (string) $raw, 42201);
        return date('Y-m-d H:i:s', $ts);
    }
}
