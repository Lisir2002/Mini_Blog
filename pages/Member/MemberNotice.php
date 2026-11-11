<?php
declare(strict_types=1);
namespace Wx\Pages\Member;

/**
 * 提交结果的回显 —— 成功与失败各说各的，绝不共用一句「操作完成」。
 *
 * 失败留在原页（不 303），表单输入原样回填；成功 303 回来后由这里根据
 * outcome 说清「实际发生了什么」：加了 0 分、被上限截断、幂等重放、本来就没变，
 * 都要有各自的说法，否则管理员会以为点一下就一定加分 / 一定改了配置。
 */
final class MemberNotice
{
    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    public static function build(array $data): array
    {
        $out = [];
        $error = $data['error'] ?? null;
        if (is_array($error) && ($error['ok'] ?? true) === false) {
            $out[] = ['type' => 'notice', 'id' => 'member-write-failed', 'tone' => 'warn',
                'state' => 'write-failed', 'title' => '提交没有生效（HTTP '
                    . (int) ($error['status'] ?? 422) . '）',
                'value' => (string) ($error['message'] ?? '未知原因')
                    . ' 表单里已经填的内容保留在下面，没有跳转，改完直接再提交。'];
        }

        $saved = (array) ($data['saved'] ?? []);
        $action = (string) ($saved['saved'] ?? '');
        if ($action === '') return $out;
        $outcome = (string) ($saved['outcome'] ?? '');
        $detail  = (string) ($saved['detail'] ?? '');
        $hint    = (string) ($saved['hint'] ?? '');
        if ($hint !== '') $detail .= ' ' . $hint;

        $out[] = ['type' => 'notice', 'id' => 'member-write-result',
            'tone' => in_array($outcome, ['duplicate', 'daily_cap', 'disabled', 'zero', 'no-change'], true)
                ? 'warn' : 'info',
            'state' => $outcome === '' ? 'saved' : $outcome,
            'title' => self::title($outcome), 'value' => $detail];
        return $out;
    }

    private static function title(string $outcome): string
    {
        return match ($outcome) {
            'duplicate'  => '同一个 event_key，没有重复加分',
            'daily_cap'  => '今日已达上限，本次加 0 分',
            'disabled'   => '规则已停用，本次加 0 分',
            'zero'       => '本次实际加了 0 分',
            'truncated'  => '发放量被每日上限截断',
            'applied'    => '经验已发放',
            'no-change'  => '没有发生变化',
            'delete'     => '定义已删除',
            'grant'      => '头衔已授予',
            'revoke'     => '手动授予已撤销',
            default      => '改动已保存',
        };
    }
}
