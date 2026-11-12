<?php
declare(strict_types=1);
namespace Wx\Pages\Queue;

/**
 * PRG 结果回显 —— 只有真的提交过才说话。
 *
 * 两条纪律：
 *  1. 普通 ?view= / ?slug= 导航不带 saved，绝不误报「已保存」；
 *  2. 「已软删」「本来就已删除」「状态已迁移」「提交没有生效」是四种不同事实，
 *     接口都返回 200 或 4xx 时不加区分，就会把幂等与失败说成同一件事。
 */
final class QueueNotice
{
    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    public static function build(array $data): array
    {
        $failed = is_array($data['failed'] ?? null) ? $data['failed'] : null;
        if ($failed !== null) {
            return [self::block('queue-failed', 'warn', 'failed', '提交没有生效',
                (string) ($failed['message'] ?? '')
                . ' 页面已重新载入最新数据；票据有效期 20 分钟，过期请刷新本页重试。')];
        }
        if ((string) ($data['error'] ?? '') !== '') {
            return [self::block('queue-read-failed', 'warn', 'unavailable', '页面数据读取失败',
                (string) $data['error'])];
        }
        $saved = is_array($data['saved'] ?? null) ? $data['saved'] : null;
        if ($saved === null) return [];

        $outcome = (string) ($saved['outcome'] ?? '');
        $detail  = (string) ($saved['detail'] ?? '');
        $hint    = (string) ($saved['hint'] ?? '');
        if ($outcome === 'deleted') {
            return [self::block('queue-saved', 'ok', 'deleted', '已软删', $detail . ' ' . $hint)];
        }
        if ($outcome === 'already') {
            return [self::block('queue-saved', 'info', 'no-change', '本来就已删除', $detail . ' ' . $hint)];
        }
        if ($outcome === 'changed') {
            return [self::block('queue-saved', 'ok', 'changed', '状态已迁移', $detail . ' ' . $hint)];
        }
        return [self::block('queue-saved', 'warn', 'unknown', '提交结果无法识别',
            '回显参数不完整，请刷新页面核对最新状态，不要假定改动已生效。')];
    }

    /** @return array<string, mixed> */
    private static function block(string $id, string $tone, string $state,
        string $title, string $value): array
    {
        return ['type' => 'notice', 'id' => $id, 'tone' => $tone,
            'state' => $state, 'title' => $title, 'value' => trim($value)];
    }
}
