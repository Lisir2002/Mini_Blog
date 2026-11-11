<?php
declare(strict_types=1);
namespace Wx\Systems\Comment;

use InvalidArgumentException;
use Wx\Contracts\CommentStoreInterface;
use Wx\Contracts\ContentRepositoryInterface;
use Wx\Systems\Data\DataSessionInterface;
use Wx\Core\Kernel;
use Wx\Systems\Api\ApiOperationException;

/**
 * 评论写入协作者 —— 发评论与回评共用同一条落库路径，差别只在「有没有父节点」。
 *
 * 拆出来不是为了分层好看，而是因为写入要同时满足四件事：文章存在、父节点同属一篇、
 * 深度不越界、正文合规。四件事任何一件缺了都会产生孤儿数据或渲染异常，
 * 所以让它们只在一个地方发生，读路径无法绕过。
 */
final class CommentWriter
{
    public function __construct(
        private CommentStoreInterface $store,
        private CommentGuard $guard,
        private Kernel $kernel,
        private ?CommentThrottle $throttle = null
    ) {}

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public function create(array $in, ?int $parentId = null): array
    {
        $slug = trim((string) ($in['post_slug'] ?? $in['slug'] ?? ''));
        if ($slug === '') throw new InvalidArgumentException('post_slug 必填');
        $this->assertPostExists($slug);

        $parent = null;
        if ($parentId !== null) $parent = $this->resolveParent($parentId, $slug);

        $depth = CommentTree::depthFor($parent);
        $maxDepth = (int) $this->kernel->config()->get('comment.max_depth', 5);
        if ($depth > $maxDepth) {
            throw new InvalidArgumentException("嵌套已达 {$maxDepth} 层上限，请直接回复上层评论");
        }

        $body = $this->guard->inspect((string) ($in['body'] ?? ''));
        $identity = $in['_identity'] ?? null;
        $draft = (array) $this->kernel->hooks()->apply('comment.before_save', [
            'post_slug' => $slug,
            'parent_id' => $parent === null ? CommentTree::ROOT : (int) $parent['id'],
            'depth'     => $depth,
            'author'    => trim((string) ($in['author'] ?? '')),
            'author_id' => (int) ($identity['user_id'] ?? 0),
            'body'      => $body,
            'ip_hash'   => self::ipHash($this->kernel, (string) ($in['_ip'] ?? '')),
        ]);

        // 节流在全部校验通过之后、真正落库之前：空正文 / 超长 / 链接过多 / 文章不存在
        // 这类失败请求不该扣掉写入额度，否则用户连打几次错别字就被莫名限流。
        $this->assertThrottle($in);

        // 路径依赖自增 id，只能落库后补写；root_id 决定「取整棵子树」只需一次前缀查询。
        // 两句必须同生共死：补写失败却留下 path='' 的行，等于往树里塞一个永远找不到的节点。
        $persist = function () use ($draft, $parent): array {
            $id = $this->store->add($draft);
            $this->store->update($id, [
                'path'    => CommentTree::pathFor($parent, $id),
                'root_id' => $parent === null ? $id : (int) ($parent['root_id'] ?? $parent['id']),
            ]);
            return $this->store->find($id) ?? $draft + ['id' => $id];
        };
        $session = $this->session();
        $row = $session === null ? $persist() : $session->transaction($persist);

        $this->kernel->hooks()->emit('comment.created', (int) ($row['id'] ?? 0), $row, $identity);
        return $row;
    }

    /** 只统计「通过校验、即将落库」的写入；主体不可识别时放行，不把匿名写入变成 429。 */
    private function assertThrottle(array $in): void
    {
        if ($this->throttle === null || !$this->throttle->enabled()) return;
        $who = CommentActor::fromKernel($this->kernel)->keyOrNull(
            $in['_identity'] ?? null, (string) ($in['_ip'] ?? ''), (string) ($in['visitor_id'] ?? '')
        );
        if ($who === null) return; // 主体不可识别：放行，不把匿名写入变成 429
        $r = $this->throttle->check($who['key']);
        if (!$r['allowed']) {
            throw new ApiOperationException('评论发表过于频繁，请稍后重试', 42903);
        }
    }

    /** @return array<string, mixed> */
    private function resolveParent(int $parentId, string $slug): array
    {
        $parent = $this->store->find($parentId);
        if ($parent === null) throw new ApiOperationException('要回复的评论不存在', 40403);
        if ((string) ($parent['post_slug'] ?? $parent['slug'] ?? '') !== $slug) {
            throw new InvalidArgumentException('父评论不属于这篇文章');
        }
        // 已删除的评论保留占位以维持对话结构，但不再接受新回复：占位节点不是有效对话对象。
        if (CommentPolicy::isDeleted($parent)) {
            throw new InvalidArgumentException('该评论已删除，无法回复');
        }
        if ((string) ($parent['status'] ?? '') === CommentPolicy::SPAM) {
            throw new ApiOperationException('要回复的评论不可见', 40403);
        }
        return $parent;
    }

    private function assertPostExists(string $slug): void
    {
        $container = $this->kernel->container();
        if (!$container->has(ContentRepositoryInterface::class)) {
            throw new ApiOperationException('内容系统不可用，评论被拒绝', 40303);
        }
        /** @var ContentRepositoryInterface $repo */
        $repo = $container->resolve(ContentRepositoryInterface::class);
        if ($repo->findBySlug($slug) === null) {
            throw new ApiOperationException('文章不存在', 40403);
        }
    }

    /** 只留不可逆指纹，既能做频率关联，也不把访客 IP 明文写进业务库。 */
    private static function ipHash(Kernel $kernel, string $ip): string
    {
        return $ip === '' ? '' : substr(hash('sha256', $ip . '|' . $kernel->root()), 0, 32);
    }

    /** 数据层缺席时退化为「无事务」：内核可以没有 database，但写入路径不能因此直接崩。 */
    private function session(): ?DataSessionInterface
    {
        $container = $this->kernel->container();
        return $container->has(DataSessionInterface::class)
            ? $container->resolve(DataSessionInterface::class) : null;
    }
}
