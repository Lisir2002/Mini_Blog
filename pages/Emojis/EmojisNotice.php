<?php
declare(strict_types=1);
namespace Wx\Pages\Emojis;

/**
 * PRG 结果回显 —— 只有真的提交过才说话。
 *
 * 「已登记」「已删除」「提交没有生效」分开；普通 ?kind= 导航不带 saved，
 * 绝不误报。目录读失败时也不会把「不可用」写成「还没有表情」。
 */
final class EmojisNotice
{
    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    public static function build(array $data): array
    {
        $failed = is_array($data['failed'] ?? null) ? $data['failed'] : null;
        if ($failed !== null) {
            return [self::block('emojis-failed', 'warn', 'failed', '提交没有生效',
                (string) ($failed['message'] ?? '')
                . ' 页面已重新载入最新目录；票据有效期 20 分钟，过期请刷新本页重试。')];
        }
        if ((string) ($data['error'] ?? '') !== '') {
            return [self::block('emojis-read-failed', 'warn', 'unavailable', '目录读取失败',
                (string) $data['error'])];
        }
        $saved = is_array($data['saved'] ?? null) ? $data['saved'] : null;
        if ($saved === null) return [];

        $outcome = (string) ($saved['outcome'] ?? '');
        $detail  = (string) ($saved['detail'] ?? '');
        $hint    = (string) ($saved['hint'] ?? '');
        if ($outcome === 'created') {
            return [self::block('emojis-saved', 'ok', 'created', '已登记自定义表情', $detail . ' ' . $hint)];
        }
        if ($outcome === 'deleted') {
            return [self::block('emojis-saved', 'ok', 'deleted', '已删除自定义表情', $detail . ' ' . $hint)];
        }
        return [self::block('emojis-saved', 'warn', 'unknown', '提交结果无法识别',
            '回显参数不完整，请刷新页面核对最新目录，不要假定改动已生效。')];
    }

    /** @return array<string, mixed> */
    private static function block(string $id, string $tone, string $state,
        string $title, string $value): array
    {
        return ['type' => 'notice', 'id' => $id, 'tone' => $tone,
            'state' => $state, 'title' => $title, 'value' => trim($value)];
    }
}
