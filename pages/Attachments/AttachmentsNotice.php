<?php
declare(strict_types=1);
namespace Wx\Pages\Attachments;

/**
 * 附件库的结果回显 —— 失败留在原页（不 303），成功 303 后按 outcome 说实际结果。
 *
 * `already` 与 `delete` 都是「没有抛错」，但前者是「本来就已经删了」；
 * 混成一句「删除成功」会让管理员以为这次真的删掉了一条记录。
 */
final class AttachmentsNotice
{
    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    public static function build(array $data): array
    {
        $out = [];
        $error = $data['error'] ?? null;
        if (is_array($error) && ($error['ok'] ?? true) === false) {
            $out[] = ['type' => 'notice', 'id' => 'attach-write-failed', 'tone' => 'warn',
                'state' => 'write-failed', 'title' => '提交没有生效（HTTP '
                    . (int) ($error['status'] ?? 422) . '）',
                'value' => (string) ($error['message'] ?? '未知原因')
                    . ' 表单里已经填的内容保留在下面，没有跳转。'];
        }
        $saved = (array) ($data['saved'] ?? []);
        $op = (string) ($saved['saved'] ?? '');
        if ($op === '') return $out;
        $detail = (string) ($saved['detail'] ?? '');
        $hint = (string) ($saved['hint'] ?? '');
        if ($hint !== '') $detail .= ' ' . $hint;
        $outcome = (string) ($saved['outcome'] ?? '');
        $out[] = ['type' => 'notice', 'id' => 'attach-write-result',
            'tone' => $outcome === 'already' ? 'warn' : 'info',
            'state' => $outcome === '' ? 'saved' : $outcome,
            'title' => self::title($outcome), 'value' => $detail];
        return $out;
    }

    private static function title(string $outcome): string
    {
        return match ($outcome) {
            'rename'  => '展示名已修改',
            'public', 'private' => '可见性已修改',
            'move'    => '挂载文章已修改',
            'delete'  => '附件已软删除',
            'already' => '此前已删除，本次没有变化',
            default   => '改动已保存',
        };
    }
}
