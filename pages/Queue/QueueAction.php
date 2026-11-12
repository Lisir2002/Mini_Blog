<?php
declare(strict_types=1);
namespace Wx\Pages\Queue;

use InvalidArgumentException;
use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Http\Request;
use Wx\Systems\Api\ApiOperationException;
use Wx\Systems\Api\Envelope;

/**
 * 审核处置 —— 唯一的写入口，把「表单 → 操作」收敛成可穷举的纯逻辑。
 *
 * 四条纪律：
 *  1. 动作白名单之外一律 422，绝不把未知值当成某个默认处置（那是「安静地改数据」）。
 *  2. 身份只来自守卫验签后的结果；身份缺失直接拒绝，不在页面里兜底成匿名管理员。
 *  3. 失败码由接口层同一张表翻译（Envelope::statusOf），页面不自己发明第二套映射。
 *  4. 删除与状态迁移是两个权限点：迁移靠 comment.moderate（守卫已过闸），
 *     删除必须现场再问 comment.delete.any —— 页面直调 execute() 会绕过分发器，
 *     不在 Action 里裁决就等于把删除权交给任何能进这个页的人。
 */
final class QueueAction
{
    /** @param list<string> $allowed @param array<string, mixed>|null $identity
     *  @return array<string, mixed> */
    public static function handle(ApiRegistryInterface $registry, Request $request,
        array $allowed, ?array $identity, ?AccessControlInterface $acl): array
    {
        $body = $request->body();
        $op   = trim((string) ($body['op'] ?? ''));
        if ($op === 'delete') return self::delete($registry, $body, $identity, $acl);
        if ($op !== '') return self::fail(422, 42201,
            '未知操作「' . $op . '」：这里只接受状态迁移或 delete。');

        $id = (int) ($body['id'] ?? 0);
        $to = trim((string) ($body['to'] ?? ''));
        if ($id <= 0) return self::fail(422, 42201, '评论 id 缺失或不是正整数');
        if (!in_array($to, $allowed, true)) {
            return self::fail(422, 42201,
                '处置动作只能是 ' . implode(' / ', $allowed) . '，当前为「' . ($to === '' ? '(空)' : $to) . '」');
        }
        if ($identity === null) {
            // 守卫已验票，走到这里说明页面数据与请求不匹配；宁可拒绝也不猜一个身份。
            return self::fail(401, 40101, '身份未建立：表单票据未通过校验，请刷新页面后重试');
        }

        try {
            $registry->execute('comments.moderate', [
                'id' => $id, 'status' => $to, '_identity' => $identity,
            ]);
        } catch (\Throwable $e) {
            return self::fromThrowable($e);
        }
        return self::ok('moderate', 'changed', true, '评论 #' . $id . ' 已迁移到 ' . $to . '。',
            '状态机只接受 published / hidden / spam；deleted 是终态，既不能迁入也不能迁出。');
    }

    /** @param array<string, mixed> $body @param array<string, mixed>|null $identity
     *  @return array<string, mixed> */
    private static function delete(ApiRegistryInterface $registry, array $body,
        ?array $identity, ?AccessControlInterface $acl): array
    {
        $id      = (int) ($body['id'] ?? 0);
        $confirm = trim((string) ($body['confirm'] ?? ''));
        if ($id <= 0) return self::fail(422, 42201, '评论 id 缺失或不是正整数');
        if ($confirm !== (string) $id) {
            return self::fail(422, 42201, '删除是终态操作，请手写评论 id ' . $id
                . ' 确认（当前填的是「' . ($confirm === '' ? '(空)' : $confirm) . '」）。');
        }
        if ($identity === null) {
            return self::fail(401, 40101, '身份未建立：表单票据未通过校验，请刷新页面后重试');
        }
        if ($acl === null || !$acl->allows($identity, 'comment.delete.any')) {
            return self::fail(403, 40303,
                '删除评论要求 comment.delete.any：能审核（comment.moderate）不等于能删任意评论。');
        }

        try {
            $r = $registry->execute('comments.delete', ['id' => $id, '_identity' => $identity]);
        } catch (\Throwable $e) {
            return self::fromThrowable($e);
        }
        $already = (bool) ($r['already'] ?? false);
        // deleted=false / already=true 不是失败：接口 200 幂等，页面也必须说清「本来就已删」。
        return self::ok('delete', $already ? 'already' : 'deleted', !$already,
            $already ? '评论 #' . $id . ' 本来就是已删除状态。' : '评论 #' . $id . ' 已软删。',
            $already
                ? '幂等：重复删除不报错，也没有重复写库。'
                : '正文与署名已清空，但 path / parent_id 保留，子回复仍显示在「该评论已删除」下；'
                    . 'deleted 是终态，不能恢复，也不能再审核。');
    }

    /** @return array<string, mixed> */
    private static function ok(string $op, string $outcome, bool $changed,
        string $detail, string $hint): array
    {
        return ['ok' => true, 'status' => 200, 'code' => 0, 'message' => 'ok',
            'op' => $op, 'outcome' => $outcome, 'changed' => $changed,
            'detail' => $detail, 'hint' => $hint];
    }

    /** @return array<string, mixed> */
    public static function fromThrowable(\Throwable $e): array
    {
        if ($e instanceof ApiOperationException) {
            $status = Envelope::statusOf($e->apiCode);
            // 未登记的业务码在表里落到 500，那是「服务器内部错误」的误报：
            // 4xxxx 一律是请求问题（422），5xxxx 才是服务端问题。
            if ($status === 500 && $e->apiCode < 50000) $status = 422;
            return self::fail($status, $e->apiCode, $e->getMessage());
        }
        if ($e instanceof InvalidArgumentException) {
            return self::fail(422, 42201, $e->getMessage());
        }
        return self::fail(503, 50301, '审核操作失败：' . $e->getMessage());
    }

    /** @return array<string, mixed> */
    private static function fail(int $status, int $code, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'code' => $code, 'message' => $message];
    }
}
