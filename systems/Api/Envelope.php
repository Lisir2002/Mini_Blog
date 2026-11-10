<?php
declare(strict_types=1);
namespace Wx\Systems\Api;

/**
 * 统一响应信封与错误码表。
 *
 * 借鉴 RFC 9457「Problem Details」的机器可读错误思想，但采用更轻的自研结构：
 *   type/title/status/detail/instance → code/message/errors/request_id/meta
 * code 稳定可分支，errors 承载字段级明细，request_id 用于链路追踪。
 */
final class Envelope
{
    public const OK = 0;

    /** @var array<int, array{0: int, 1: string}> code => [HTTP 状态, 简要说明] */
    public const TABLE = [
        40401 => [404, '接口不存在'],
        40402 => [404, '装卸单元不存在'],
        40403 => [404, '资源不存在'],
        40501 => [405, 'HTTP 方法不被允许'],
        42201 => [422, '参数校验未通过'],
        40901 => [409, '状态冲突'],
        40101 => [401, '未认证'],
        40102 => [401, '令牌无效或已过期'],
        40301 => [403, '无权限执行该操作'],
        40302 => [403, '写接口未开放公网访问'],
        40303 => [403, '权限决策未放行（fail-closed）'],
        40902 => [409, '幂等键重放参数不一致'],
        40905 => [409, '同一幂等键的请求正在处理中'],
        40903 => [409, '迁移不可逆，拒绝回退'],
        40904 => [409, '迁移校验和冲突，拒绝升级'],
        42901 => [429, '请求过于频繁'],
        50001 => [500, '服务器内部错误'],
        50002 => [500, '迁移执行失败'],
        50003 => [500, '升级后健康检查未通过'],
        50004 => [500, '数据库不可用'],
        50101 => [501, '接口已登记但执行者未实现'],
    ];

    public static function statusOf(int $code): int
    {
        return self::TABLE[$code][0] ?? 500;
    }

    /** @param array<string, mixed> $meta */
    public static function ok(mixed $data, string $requestId, array $meta = []): array
    {
        return [
            'code'       => self::OK,
            'message'    => 'ok',
            'data'       => $data,
            'request_id' => $requestId,
            'meta'       => $meta,
        ];
    }

    /** @param list<array<string, mixed>> $errors */
    public static function error(int $code, string $requestId, array $errors = [], ?string $detail = null, array $meta = []): array
    {
        $body = [
            'code'       => $code,
            'message'    => self::TABLE[$code][1] ?? '未知错误',
            'data'       => null,
            'request_id' => $requestId,
            'errors'     => $errors,
            'meta'       => $meta,
        ];
        if ($detail !== null) $body['detail'] = $detail;
        return $body;
    }
}
