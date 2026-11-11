<?php
declare(strict_types=1);
namespace Wx\Pages\Queue;

use InvalidArgumentException;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Http\Request;
use Wx\Systems\Api\ApiOperationException;
use Wx\Systems\Api\Envelope;

/**
 * 审核处置 —— 唯一的写入口，把「表单 → 操作」收敛成可穷举的纯逻辑。
 *
 * 三条纪律：
 *  1. 动作白名单之外一律 422，绝不把未知值当成某个默认处置（那是「安静地改数据」）。
 *  2. 身份只来自守卫验签后的结果；身份缺失直接拒绝，不在页面里兜底成匿名管理员。
 *  3. 失败码由接口层同一张表翻译（Envelope::statusOf），页面不自己发明第二套映射。
 */
final class QueueAction
{
    /** @param list<string> $allowed
     *  @return array{ok: bool, status: int, code: int, message: string} */
    public static function handle(ApiRegistryInterface $registry, Request $request,
        array $allowed, ?array $identity): array
    {
        $body = $request->body();
        $id   = (int) ($body['id'] ?? 0);
        $to   = trim((string) ($body['to'] ?? ''));

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
        return ['ok' => true, 'status' => 200, 'code' => 0, 'message' => 'ok'];
    }

    /** @return array{ok: bool, status: int, code: int, message: string} */
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

    /** @return array{ok: bool, status: int, code: int, message: string} */
    private static function fail(int $status, int $code, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'code' => $code, 'message' => $message];
    }
}
