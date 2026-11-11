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
            // 多读一条只用于判断「还有没有」：limit 本身只保证上限，不表达是否被截断。
            $limit = max(0, (int) $this->kernel->config()->get('comment.read_limit', 2000));
            $raw = $this->store->forPost($slug, $limit > 0 ? $limit + 1 : 0);
            $truncated = $limit > 0 && count($raw) > $limit;
            if ($truncated) $raw = array_slice($raw, 0, $limit);

            $rows = $this->pruneHidden(array_values(array_filter(
                $raw,
                static fn(array $row): bool => CommentPolicy::visible($identity, $row, $acl)
            )));
            $items = array_map(fn(array $row): array => $this->present($row, $identity, $acl), $rows);
            // 互动台账由旁路层批量补上（三条 SQL，与条数无关）。主体不可识别时退化为
            // 空 key：列表仍可读，只是 liked/reported 恒 false —— 读接口不该因为「没带
            // visitor_id」就整页 500，那是写操作才需要的严格性。
            $actorKey = '';
            try {
                $actorKey = CommentActor::fromKernel($this->kernel)->resolve(
                    $identity, (string) ($in['_ip'] ?? ''), (string) ($in['visitor_id'] ?? '')
                )['key'];
            } catch (InvalidArgumentException) {}
            $items = (array) $this->kernel->hooks()->apply('comment.present_many', $items, $actorKey);
            return [
                'post_slug' => $slug,
                'total'     => $this->store->countForPost($slug),
                // 截断必须显式声明：静默只返回前 2000 条，客户端会以为评论就这么多。
                'truncated' => $truncated,
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
        $deleted = CommentPolicy::isDeleted($row);
        $own = CommentPolicy::ownedBy($identity, $row);
        // 显式白名单，不是 unset：仓储给的是物理行（含 ip_hash / path / author_id），
        // 出站必须按契约裁剪。用「列出允许的」而不是「删掉敏感的」，加列时才不会默认泄漏。
        return [
            'id'         => (int) ($row['id'] ?? 0),
            'parent_id'  => (int) ($row['parent_id'] ?? 0),
            'depth'      => (int) ($row['depth'] ?? 0),
            'author'     => (string) ($row['author'] ?? ''),
            'body'       => $body,
            'status'     => (string) ($row['status'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'deleted'    => $deleted,
            'own'        => $own,
            // v0.29 之前这里是 `own && !$deleted`：主题据此渲染编辑按钮，但全站
            // 根本没有编辑接口 —— 一张永远兑现不了的支票。现在用同一套策略判定，
            // 窗口 / 状态 / 归属任一不满足就不给按钮，字段与后端真实能力一致。
            'editable'   => CommentInteractionPolicy::editable(
                $row, $identity, $acl, time(),
                (int) $this->kernel->config()->get('comment.edit_window_minutes', 10)
            )['ok'],
            'edit_count' => (int) ($row['edit_count'] ?? 0),
            'edited_at'  => isset($row['edited_at']) ? (string) $row['edited_at'] : null,
            'deletable'  => CommentPolicy::canDelete($identity, $row, $acl),
            'moderatable' => CommentPolicy::canModerate($identity, $acl),
            'tokens'     => $this->kernel->hooks()->apply(
                'emoji.parse', [['type' => 'text', 'value' => $body]], $body
            ),
        ];
    }

    private function acl(): ?AccessControlInterface
    {
        $container = $this->kernel->container();
        return $container->has(AccessControlInterface::class)
            ? $container->resolve(AccessControlInterface::class) : null;
    }

    /**
     * 父评论存在但不可见时，整枝隐藏 —— 而不是让树构建把回复「提升为根」。
     *
     * 两种缺失必须分开：父行根本不存在是脏数据，提升为根是合理的兜底；
     * 父行存在只是被隐藏 / 待审，说明这条回复不该出现在当前视角，
     * 提升会让用户看到一条「凭空出现、没有上文」的评论。
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function pruneHidden(array $rows): array
    {
        $visible = [];
        foreach ($rows as $row) $visible[(int) ($row['id'] ?? 0)] = true;
        $memo = [];

        return array_values(array_filter($rows, function (array $row) use ($visible, &$memo): bool {
            $parent = (int) ($row['parent_id'] ?? 0);
            if ($parent === 0) return true;
            if (isset($visible[$parent])) return true;
            if (!array_key_exists($parent, $memo)) $memo[$parent] = $this->store->find($parent);
            // 父行不存在：脏数据，交给 CommentTree::build 提升为根，不在这里丢内容。
            return $memo[$parent] === null;
        }));
    }
}
