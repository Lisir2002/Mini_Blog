<?php
declare(strict_types=1);
namespace Wx\Pages\Terms;

/**
 * 分类与标签页的结果回显 —— 只有 PRG 带回来的 saved 才算一次提交结果。
 *
 * 普通 ?slug= / ?kind= 导航不该凭空报「已保存」，这与「不能静默出错」是同一条
 * 规矩的另一面：也不能凭空报成功。no-change 单独标成 warn，因为接口对
 * updated=false / 整体替换幂等都返回 200，一律说「已保存」会骗人。
 */
final class TermsNotice
{
    private const TITLES = [
        'create_category' => '已新建分类', 'create_tag' => '已新建标签',
        'term_update' => '术语已更新', 'term_delete' => '术语已删除',
        'attach' => '文章挂载已保存',
    ];

    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    public static function build(array $data): array
    {
        $out = [];
        $error = $data['error'] ?? null;
        if (is_array($error) && ($error['ok'] ?? true) === false) {
            $out[] = ['type' => 'notice', 'id' => 'terms-write-failed', 'tone' => 'warn',
                'state' => 'write-failed', 'title' => '提交没有生效（HTTP '
                    . (int) ($error['status'] ?? 422) . '）',
                'value' => (string) ($error['message'] ?? '未知原因')
                    . ' 表单里已经填的内容保留在下面，没有跳转。'];
        }
        $saved = (array) ($data['saved'] ?? []);
        if ((string) ($saved['saved'] ?? '') === '') return $out;
        $op = (string) ($saved['op'] ?? '');
        $outcome = (string) ($saved['outcome'] ?? '');
        $detail = (string) ($saved['detail'] ?? '');
        $hint = (string) ($saved['hint'] ?? '');
        if ($hint !== '') $detail .= ' ' . $hint;
        $out[] = ['type' => 'notice', 'id' => 'terms-write-result',
            'tone' => $outcome === 'no-change' ? 'warn' : 'info',
            'state' => $outcome === '' ? 'saved' : $outcome,
            'title' => $outcome === 'no-change'
                ? '没有发生变化' : (self::TITLES[$op] ?? '改动已保存'),
            'value' => $detail];
        return $out;
    }
}
