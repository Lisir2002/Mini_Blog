<?php
declare(strict_types=1);
namespace Wx\Pages\Member;

/**
 * 成长操作台 —— 五个写动作，一次只显示一个表单。
 *
 * 为什么用 ?panel= 切换而不是把五张表叠在一页：五个动作共二十多个字段，
 * 叠在一起只能靠 placeholder 猜「哪些格子对这次提交生效」；用 GET 切换后
 * 每张表单的字段就是它真正会提交的全部参数，且不依赖脚本、可收藏、可回退。
 *
 * 没有权限或票据签不出来时**不渲染表单** —— 渲染一个点了必然 403 的按钮，
 * 比明说「你没有这个权限」更糟。
 */
final class MemberForms
{
    private const LABELS = [
        'award' => '发放经验', 'xp_rule' => '经验规则', 'title_def' => '头衔定义',
        'title_grant' => '授予头衔', 'level_def' => '等级定义',
    ];

    /** @return list<array<string, mixed>> */
    public static function build(array $data): array
    {
        $panel  = (string) ($data['panel'] ?? 'award');
        $ticket = (string) ($data['ticket'] ?? '');
        $can    = (array) ($data['can'] ?? []);
        $post   = (array) ($data['post'] ?? []);
        $blocks = [self::switcher($panel, $data)];

        if (!isset(self::LABELS[$panel])) {
            $blocks[] = self::notice('member-panel-unknown', '未知的操作台', 'panel 只能是 '
                . implode(' / ', array_keys(self::LABELS)) . '。', 'bad-param');
            return $blocks;
        }
        if ($ticket === '') {
            $blocks[] = self::notice('member-no-ticket', '写入口不可用',
                '表单票据签不出来（storage 不可写或身份未建立）。本页只读展示，不会渲染一个点了必然失败的提交按钮。',
                'unavailable');
            return $blocks;
        }
        $permission = MemberAction::PERMISSIONS[$panel];
        if (($can[$panel] ?? false) !== true) {
            $blocks[] = self::notice('member-no-permission', '没有「' . self::LABELS[$panel] . '」的权限',
                '这一步要求 ' . $permission . '。上面的只读视图仍然有效 —— '
                . '能看到成长数据，不等于能改它。', 'permission-denied');
            return $blocks;
        }

        $spec = MemberFormSpec::spec($panel);
        $hidden = [['name' => '_form_ticket', 'value' => $ticket], ['name' => 'action', 'value' => $panel]];
        $fields = [];
        foreach ($spec['fields'] as $field) {
            $name = $field['name'];
            $fields[] = ['tag' => 'input', 'id' => 'mf-' . $panel . '-' . $name, 'name' => $name,
                'type' => 'text', 'value' => (string) ($post[$name] ?? ''),
                'placeholder' => $field['hint']];
        }
        foreach ($spec['submit'] as $button) $fields[] = $button;

        $blocks[] = ['type' => 'notice', 'id' => 'member-form-intro', 'tone' => 'info',
            'state' => 'guide', 'title' => self::LABELS[$panel] . ' · 怎么填', 'value' => $spec['intro']];
        $blocks[] = ['type' => 'form', 'id' => 'member-form', 'title' => self::LABELS[$panel],
            'action' => '/console/member', 'method' => 'post', 'hidden' => $hidden,
            'fields' => $fields, 'note' => $spec['note']];
        return $blocks;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function switcher(string $panel, array $data): array
    {
        $keep = [];
        foreach (['user', 'offset'] as $key) {
            $value = (string) ($data[$key] ?? '');
            if ($value !== '') $keep[$key] = $value;
        }
        $items = [];
        foreach (self::LABELS as $key => $label) {
            $items[] = ['label' => $label, 'active' => $key === $panel,
                'href' => '/console/member?' . http_build_query($keep + ['panel' => $key])];
        }
        return ['type' => 'links', 'id' => 'member-panels', 'title' => '操作台 · 选择要做的改动',
            'items' => $items];
    }

    /** @return array<string, mixed> */
    private static function notice(string $id, string $title, string $value, string $state): array
    {
        return ['type' => 'notice', 'id' => $id, 'tone' => $state === 'unavailable' ? 'warn' : 'info',
            'state' => $state, 'title' => $title, 'value' => $value];
    }
}
