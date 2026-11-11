<?php
declare(strict_types=1);
namespace Wx\Systems\Comment;

use InvalidArgumentException;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Contracts\CommentStoreInterface;
use Wx\Core\Kernel;
use Wx\Systems\Api\ApiOperationException;

/**
 * 互动接口执行者 —— 点赞、举报、编辑、审核队列，外加给列表补上互动字段。
 *
 * 与 CommentOperations 的分工：那边管「评论本身」（发/删/审/树），
 * 这边管「评论之间的关系与人」（谁点了赞、谁举报、谁在改）。
 * 拆开不是为分层好看，而是两边的写入路径、幂等口径、失败码完全不同。
 */
final class CommentInteractionOperations
{
    private CommentActor $actor;

    public function __construct(
        private CommentStoreInterface $comments,
        private CommentInteractionStoreInterface $interactions,
        private CommentGuard $guard,
        private Kernel $kernel
    ) {
        $this->actor = CommentActor::fromKernel($kernel);
    }

    public function register(ApiRegistryInterface $registry): void
    {
        $registry->registerOperation('comments.like', fn(array $in): array => $this->like($in));
        $registry->registerOperation('comments.report', fn(array $in): array => $this->report($in));
        $registry->registerOperation('comments.edit', fn(array $in): array => $this->edit($in));
        $registry->registerOperation('comments.queue', fn(array $in): array => $this->queue($in));

        // 列表补字段：一次批量查询，不随评论条数增长。没有本钩子时（互动层未装配）
        // 列表仍然能返回，只是不带 likes/reports —— 读路径永不因旁路能力缺席而 500。
        // 主体键由列表执行者显式传入：装饰器不猜身份，也不从数据行里挖上下文。
        $interactions = $this->interactions;
        $this->kernel->hooks()->filter('comment.present_many', static function (array $items, string $actorKey) use ($interactions): array {
            return CommentListDecorator::apply($items, $actorKey, $interactions);
        }, 10);
    }

    /** @param array<string, mixed> $in */
    private function like(array $in): array
    {
        $row = $this->requireRow($in);
        if (!CommentInteractionPolicy::interactable($row)) {
            throw new ApiOperationException('该评论当前状态不允许点赞', 40914);
        }
        $who = $this->who($in);
        $add = $this->flag($in['action'] ?? 'add', 'action', 'add', 'remove') === 'add';
        $id  = (int) $row['id'];
        $r   = $this->interactions->vote($id, $who['key'], $add);
        $this->kernel->hooks()->emit('comment.liked', $id, $who['kind'], $add, $r['changed']);
        return [
            'id'      => $id,
            'action'  => $add ? 'add' : 'remove',
            'changed' => $r['changed'],
            'liked'   => $r['liked'],
            'likes'   => $r['likes'],
            'actor'   => $who['kind'],
        ];
    }

    /** @param array<string, mixed> $in */
    private function report(array $in): array
    {
        $row = $this->requireRow($in);
        if (!CommentInteractionPolicy::interactable($row)) {
            throw new ApiOperationException('该评论当前状态不允许举报', 40914);
        }
        $reason = (string) ($in['reason'] ?? 'other');
        if (!CommentInteractionPolicy::reasonValid($reason)) {
            throw new InvalidArgumentException('reason 只能是 spam / abuse / offtopic / other');
        }
        $who = $this->who($in);
        $id  = (int) $row['id'];
        $r   = $this->interactions->report($id, $who['key'], $reason);

        // 阈值是「独立举报人数」，重复举报不累加，因此不会把阈值一次次重新触发。
        $threshold = (int) $this->kernel->config()->get('comment.report_threshold', 3);
        $auto = $r['changed'] && CommentInteractionPolicy::shouldAutoHide($row, $r['reports'], $threshold);
        if ($auto) {
            $this->comments->update($id, ['status' => CommentPolicy::PENDING]);
            $this->kernel->hooks()->emit('comment.auto_hidden', $id, $r['reports'], $threshold);
        }
        $this->kernel->hooks()->emit('comment.reported', $id, $who['kind'], $reason, $r['changed']);
        return [
            'id'           => $id,
            'changed'      => $r['changed'],
            'reports'      => $r['reports'],
            'threshold'    => $threshold,
            'auto_hidden'  => $auto,
            'status'       => $auto ? CommentPolicy::PENDING : (string) ($row['status'] ?? ''),
            'actor'        => $who['kind'],
        ];
    }

    /** @param array<string, mixed> $in */
    private function edit(array $in): array
    {
        $row = $this->requireRow($in);
        $id  = (int) $row['id'];
        $acl = $this->acl();
        $window = (int) $this->kernel->config()->get('comment.edit_window_minutes', 10);
        $check = CommentInteractionPolicy::editable($row, $in['_identity'] ?? null, $acl, time(), $window);
        if (!$check['ok']) {
            throw new ApiOperationException('评论不可编辑：' . $check['reason'], $check['code']);
        }

        // 编辑走的必须是与发表同一套内容守卫：否则改一个字就能绕过长度 / 链接 / 控制字符检查。
        $body = $this->guard->inspect((string) ($in['body'] ?? ''));
        $expected = (int) ($in['expected_edit_count'] ?? -1);
        if ($expected < 0) {
            throw new InvalidArgumentException('expected_edit_count 必填，取自列表返回的 edit_count');
        }
        $r = $this->interactions->edit($id, $body, $expected);
        if (!$r['changed']) {
            throw new ApiOperationException('评论已被其他编辑修改，请刷新后重试', 40913);
        }
        $this->kernel->hooks()->emit('comment.edited', $id, $r['edit_count']);
        return [
            'id'         => $id,
            'body'       => $body,
            'edit_count' => $r['edit_count'],
            'edited'     => true,
        ];
    }

    /** @param array<string, mixed> $in */
    private function queue(array $in): array
    {
        $limit  = min(200, max(1, (int) ($in['limit'] ?? 50)));
        $offset = max(0, (int) ($in['offset'] ?? 0));
        $rows   = $this->interactions->queue($limit + 1, $offset);
        $truncated = count($rows) > $limit;
        if ($truncated) array_pop($rows);

        $items = [];
        foreach ($rows as $entry) {
            $comment = $this->comments->find((int) $entry['comment_id']);
            if ($comment === null) continue;   // 台账比主表活得久：孤儿行不渲染成幽灵条目
            $items[] = [
                'id'         => (int) $entry['comment_id'],
                'post_slug'  => (string) ($comment['post_slug'] ?? ''),
                'author'     => (string) ($comment['author'] ?? ''),
                'body'       => (string) ($comment['body'] ?? ''),
                'status'     => (string) ($comment['status'] ?? ''),
                'created_at' => (string) ($comment['created_at'] ?? ''),
                'likes'      => (int) $entry['likes'],
                'reports'    => (int) $entry['reports'],
            ];
        }
        $total = $this->interactions->queueTotal();
        return [
            'items'     => $items,
            'total'     => $total,
            'limit'     => $limit,
            'offset'    => $offset,
            'truncated' => $truncated || $offset + count($items) < $total,
        ];
    }

    /** @param array<string, mixed> $in @return array{key:string, kind:string} */
    private function who(array $in): array
    {
        return $this->actor->resolve(
            $in['_identity'] ?? null,
            (string) ($in['_ip'] ?? ''),
            (string) ($in['visitor_id'] ?? '')
        );
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    private function requireRow(array $in): array
    {
        $id = (int) ($in['id'] ?? 0);
        if ($id <= 0) throw new InvalidArgumentException('id 必填且必须为正整数');
        $row = $this->comments->find($id);
        if ($row === null) throw new ApiOperationException('评论不存在', 40403);
        return $row;
    }

    private function flag(mixed $value, string $field, string ...$allowed): string
    {
        $v = (string) $value;
        if (!in_array($v, $allowed, true)) {
            throw new InvalidArgumentException($field . ' 只能是 ' . implode(' / ', $allowed));
        }
        return $v;
    }

    private function acl(): ?\Wx\Contracts\AccessControlInterface
    {
        $c = $this->kernel->container();
        return $c->has(\Wx\Contracts\AccessControlInterface::class)
            ? $c->resolve(\Wx\Contracts\AccessControlInterface::class) : null;
    }
}
