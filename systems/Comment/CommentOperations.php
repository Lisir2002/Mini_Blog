<?php
declare(strict_types=1);
namespace Wx\Systems\Comment;

use InvalidArgumentException;
use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Contracts\CommentStoreInterface;
use Wx\Core\Kernel;
use Wx\Systems\Api\ApiOperationException;

/**
 * 评论接口执行者 —— 读一棵树、发一条、删一条、审一条。
 *
 * 每个执行者都只做「取参数 → 问策略 → 调存储 → 返回事实」，
 * 不拼 HTML、不写文案、不决定颜色；呈现归主题，文案归状态层。
 */
final class CommentOperations
{
    public function __construct(
        private CommentStoreInterface $store,
        private CommentWriter $writer,
        private Kernel $kernel
    ) {}

    public function register(ApiRegistryInterface $registry): void
    {
        $registry->registerOperation('comments.list', function (array $in): array {
            $slug = (string) ($in['post_slug'] ?? $in['slug'] ?? '');
            $acl  = $this->acl();
            $identity = $in['_identity'] ?? null;
            $rows = array_values(array_filter(
                $this->store->forPost($slug),
                static fn(array $row): bool => CommentPolicy::visible($identity, $row, $acl)
            ));
            $items = array_map(fn(array $row): array => $this->present($row, $identity, $acl), $rows);
            return [
                'post_slug' => $slug,
                'total'     => $this->store->countForPost($slug),
                'items'     => $items,
                'tree'      => CommentTree::build($items),
            ];
        });

        $registry->registerOperation('comments.add', fn(array $in): array
            => $this->created($this->writer->create($in)));

        $registry->registerOperation('comments.reply', function (array $in): array {
            $parentId = (int) ($in['parent_id'] ?? 0);
            if ($parentId <= 0) throw new InvalidArgumentException('parent_id 必填且必须为正整数');
            return $this->created($this->writer->create($in, $parentId));
        });

        $registry->registerOperation('comments.delete', function (array $in): array {
            $row = $this->requireRow($in);
            $identity = $in['_identity'] ?? null;
            $acl = $this->acl();
            if (!CommentPolicy::canDelete($identity, $row, $acl)) {
                throw new ApiOperationException('无权删除该评论', 40303);
            }
            if (CommentPolicy::isDeleted($row)) {
                // 删除是幂等的：重复删除返回同一事实，不报错也不重复改库。
                return ['id' => (int) $row['id'], 'deleted' => false, 'already' => true];
            }
            $this->store->update((int) $row['id'], [
                'status'     => CommentPolicy::DELETED,
                'body'       => '',
                'author'     => '',
                'deleted_at' => date('Y-m-d H:i:s'),
            ]);
            $this->kernel->hooks()->emit('comment.deleted', (int) $row['id'], $row, $identity);
            return ['id' => (int) $row['id'], 'deleted' => true, 'already' => false];
        });

        $registry->registerOperation('comments.moderate', function (array $in): array {
            $row = $this->requireRow($in);
            $identity = $in['_identity'] ?? null;
            if (!CommentPolicy::canModerate($identity, $this->acl())) {
                throw new ApiOperationException('无审核权限', 40303);
            }
            $to = (string) ($in['status'] ?? '');
            if (!in_array($to, [CommentPolicy::PUBLISHED, CommentPolicy::HIDDEN, CommentPolicy::SPAM], true)) {
                throw new InvalidArgumentException('status 只能是 published / hidden / spam');
            }
            $from = (string) ($row['status'] ?? '');
            if (CommentPolicy::isDeleted($row)) {
                throw new ApiOperationException('已删除的评论不可再审核', 40901);
            }
            if (!CommentPolicy::canTransition($from, $to)) {
                throw new ApiOperationException("不允许从 {$from} 迁移到 {$to}", 40901);
            }
            $this->store->update((int) $row['id'], ['status' => $to]);
            $this->kernel->hooks()->emit('comment.moderated', (int) $row['id'], $to, $from, $identity);
            return ['id' => (int) $row['id'], 'from' => $from, 'to' => $to];
        });
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    private function requireRow(array $in): array
    {
        $id = (int) ($in['id'] ?? 0);
        if ($id <= 0) throw new InvalidArgumentException('id 必填且必须为正整数');
        $row = $this->store->find($id);
        if ($row === null) throw new ApiOperationException('评论不存在', 40403);
        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function created(array $row): array
    {
        return [
            'id'         => (int) ($row['id'] ?? 0),
            'parent_id'  => (int) ($row['parent_id'] ?? 0),
            'depth'      => (int) ($row['depth'] ?? 0),
            'status'     => (string) ($row['status'] ?? CommentPolicy::PENDING),
            'created'    => true,
        ];
    }

    /**
     * 单条评论的对外形态：正文原样返回，另附表情 token。
     * token 是数据不是 HTML —— 主题决定渲染成什么，模型也能直接读懂。
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row, ?array $identity, ?AccessControlInterface $acl): array
    {
        $body = (string) ($row['body'] ?? '');
        $row['id']        = (int) ($row['id'] ?? 0);
        $row['parent_id'] = (int) ($row['parent_id'] ?? 0);
        $row['depth']     = (int) ($row['depth'] ?? 0);
        $row['deleted']   = CommentPolicy::isDeleted($row);
        $row['own']       = CommentPolicy::ownedBy($identity, $row);
        $row['editable']  = $row['own'] && !$row['deleted'];
        $row['deletable'] = CommentPolicy::canDelete($identity, $row, $acl);
        $row['moderatable'] = CommentPolicy::canModerate($identity, $acl);
        $row['tokens']    = $this->kernel->hooks()->apply(
            'emoji.parse', [['type' => 'text', 'value' => $body]], $body
        );
        return $row;
    }

    private function acl(): ?AccessControlInterface
    {
        $container = $this->kernel->container();
        return $container->has(AccessControlInterface::class)
            ? $container->resolve(AccessControlInterface::class) : null;
    }
}
