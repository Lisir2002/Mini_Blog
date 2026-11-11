<?php
declare(strict_types=1);
namespace Wx\Pages\Shared;

use InvalidArgumentException;
use Wx\Systems\Api\ApiOperationException;
use Wx\Systems\Api\Envelope;

/**
 * 页面写操作的失败翻译 —— 与接口层同一张错误码表，页面不发明第二套映射。
 *
 * 单独成文件的原因：登录、注册、账号自助三处都要把执行者抛出的业务码翻成
 * 「HTTP 状态 + 可显示文案」，复制三份必然漂移。这里只做翻译，不做裁决。
 */
final class ApiFailure
{
    /** @return array{ok: false, status: int, code: int, message: string} */
    public static function describe(\Throwable $e): array
    {
        if ($e instanceof ApiOperationException) {
            $status = Envelope::statusOf($e->apiCode);
            // 未登记的 4xxxx 业务码落到表尾会变成 500「服务器内部错误」：
            // 那是把「请求有问题」误报成「服务坏了」，统一按 422 处理。
            if ($status === 500 && $e->apiCode >= 40000 && $e->apiCode < 50000) $status = 422;
            return ['ok' => false, 'status' => $status, 'code' => $e->apiCode,
                'message' => $e->getMessage()];
        }
        if ($e instanceof InvalidArgumentException) {
            return ['ok' => false, 'status' => 422, 'code' => 42201, 'message' => $e->getMessage()];
        }
        return ['ok' => false, 'status' => 503, 'code' => 50301,
            'message' => '操作未完成：' . $e->getMessage()];
    }
}
