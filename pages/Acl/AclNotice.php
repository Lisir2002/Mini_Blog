<?php
declare(strict_types=1);
namespace Wx\Pages\Acl;

/**
 * ACL 页的结果回显 —— 成功 303 后按 outcome 说实际结果，失败留在原页。
 *
 * `changed=false` 单独标成 warn：接口对「重复绑定 / 删不存在的组」都返回 200，
 * 一律说「已保存」会让管理员以为一条写错的规则生效了。
 */
final class AclNotice
{
    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    public static function build(array $data): array
    {
        $out = [];
        $error = $data['error'] ?? null;
        if (is_array($error) && ($error['ok'] ?? true) === false) {
            $out[] = ['type' => 'notice', 'id' => 'acl-write-failed', 'tone' => 'warn',
                'state' => 'write-failed', 'title' => '提交没有生效（HTTP '
                    . (int) ($error['status'] ?? 422) . '）',
                'value' => (string) ($error['message'] ?? '未知原因')
                    . ' 表单里已经填的内容保留在下面，没有跳转。'];
        }
        $saved = (array) ($data['saved'] ?? []);
        // 只有 PRG 带回来的 saved 才算一次提交结果；普通 ?panel= 导航不该凭空报「已保存」。
        $trigger = (string) ($saved['saved'] ?? '');
        if ($trigger === '') return $out;
        $panel = (string) ($saved['panel'] ?? $trigger);
        $outcome = (string) ($saved['outcome'] ?? '');
        $detail = (string) ($saved['detail'] ?? '');
        $hint = (string) ($saved['hint'] ?? '');
        if ($hint !== '') $detail .= ' ' . $hint;
        $out[] = ['type' => 'notice', 'id' => 'acl-write-result',
            'tone' => $outcome === 'no-change' ? 'warn' : 'info',
            'state' => $outcome === '' ? 'saved' : $outcome,
            'title' => $outcome === 'no-change'
                ? ($panel === 'acl' ? '没有写入新规则' : '没有发生变化')
                : ($panel === 'acl' ? '已追加一条资源 ACL' : '分组改动已保存'),
            'value' => $detail];
        return $out;
    }
}
