<?php
declare(strict_types=1);
namespace Wx\Pages\Post;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Kernel;

/**
 * 文章页的编辑提交 —— 票据换身份，其余全交给 comments.edit。
 *
 * 页面表单没有 Authorization 头，直接提交会让 _identity 恒为空。这里的翻译是：
 * 服务端在已认证的 GET 上签发过票据（见 CommentEditTicket），提交时验签取回
 * (评论, 作者, 版本)，再以该身份调用与 JSON 接口完全相同的执行者。
 *
 * 边界刻意保持：验签只证明「这张票是我们发的、没过期」，作者与版本仍由
 * comments.edit 按当下数据再判一次（归属 / 状态 / 窗口 / 乐观锁）。
 * 任一步失败都明确报错，绝不 303 假装成功。
 */
final class PostEdit
{
    public const INTENT = 'edit';

    /**
     * @param array<string, mixed> $body
     * @return array{ok: bool, status?: int, code?: int, message?: string}|null null = 不是编辑意图
     */
    public static function handle(Kernel $kernel, ApiRegistryInterface $registry, array $body): ?array
    {
        if ((string) ($body['_intent'] ?? '') !== self::INTENT) return null;

        $id = (int) ($body['comment_id'] ?? 0);
        $text = (string) ($body['body'] ?? '');
        $ticket = (string) ($body['_ticket'] ?? '');
        if ($id <= 0) return self::fail(422, 42201, '缺少评论编号');
        if (trim($text) === '') return self::fail(422, 42201, '评论内容不能为空');

        $signer = CommentEditTicket::fromKernel($kernel->root());
        if ($signer === null) {
            return self::fail(503, 50001, '编辑入口当前不可用：票据密钥不可读或存储不可写，已记录到服务端日志');
        }
        $claim = $signer->parse($ticket, time());
        if ($claim === null) {
            return self::fail(403, 40301, '编辑凭证无效或已过期，请刷新页面后重新打开编辑表单');
        }
        if ($claim['cid'] !== $id) {
            return self::fail(422, 42201, '编辑凭证与目标评论不匹配');
        }

        try {
            $registry->execute('comments.edit', [
                'id' => $id,
                'body' => $text,
                'expected_edit_count' => $claim['cnt'],
                '_identity' => ['user_id' => $claim['uid'], 'username' => ''],
            ]);
        } catch (\Throwable $e) {
            $f = PostInteraction::failure($e);
            return self::fail($f['status'], $f['code'], $f['message']);
        }
        return ['ok' => true];
    }

    /** @return array{ok: bool, status: int, code: int, message: string} */
    private static function fail(int $status, int $code, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'code' => $code, 'message' => $message];
    }
}
