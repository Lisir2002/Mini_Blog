<?php
declare(strict_types=1);

use Wx\Pages\ContentAdmin\ContentAdminBlocks;

/**
 * 69 · 排期与推荐位：面板组装（纯函数）
 *
 * 运行时闭环见 69b。本组只从 ContentAdminBlocks / ContentAdminFocusBlocks
 * 的结构化产出取证：面板只在 focus 命中时渲染、两张表单字段不混装、
 * 置顶由按钮枚举、四类不可用状态都显式说明而不是渲染假入口。
 */
echo "\n=== 69. 排期与推荐位：面板组装（纯函数） ===\n";

$data = ['items' => [[
    'slug' => 'pl-a', 'title' => '甲', 'status' => 'published',
    'publish_at' => '2026-11-14 08:30:00', 'pinned' => true,
    'sort_weight' => 42, 'deleted_at' => null,
]], 'total' => 1, 'shown' => 1, 'truncated' => false];
$allowed = ['publish' => true, 'draft' => true, 'archive' => true,
    'trash' => true, 'restore' => true, 'purge' => true];
$cap = ['can_schedule' => true, 'can_pin' => true];
$ticket = 'v53.payload';

/** @return array<string, array<string, mixed>> */
$forms = static function (array $blocks): array {
    $out = [];
    foreach ($blocks as $b) {
        if (is_array($b) && ($b['type'] ?? '') === 'form' && isset($b['id'])) {
            $out[(string) $b['id']] = $b;
        }
    }
    return $out;
};
/** @param array<string, mixed> $form */
$fieldNames = static function (array $form): array {
    return array_values(array_map(static fn ($f): string =>
        (string) (is_array($f) ? ($f['name'] ?? '') : ''), (array) ($form['fields'] ?? [])));
};

check('面板只在 focus 命中时出现，两张表单字段不混装', function () use ($data, $allowed, $cap, $ticket, $forms, $fieldNames): void {
    $none = ContentAdminBlocks::build('live', $data, $ticket, $allowed, null, false, '', $cap);
    truthy($forms($none) === [], '没选中文章却渲染了设置表单');
    truthy(str_contains(json_encode($none, JSON_UNESCAPED_UNICODE) ?: '', 'content-focus'),
        '缺少「先选一篇文章」的入口链接组');

    $blocks = ContentAdminBlocks::build('live', $data, $ticket, $allowed, null, false, 'pl-a', $cap);
    $fs = $forms($blocks);
    truthy(isset($fs['content-schedule'], $fs['content-pin']), '两张独立表单没有都渲染出来');
    $s = $fieldNames($fs['content-schedule']);
    $p = $fieldNames($fs['content-pin']);
    truthy(in_array('publish_at', $s, true) && !in_array('weight', $s, true),
        '排期表单夹带了权重字段');
    truthy(in_array('weight', $p, true) && !in_array('publish_at', $p, true),
        '推荐位表单夹带了时间字段');
    truthy($fs['content-schedule']['hidden'] === $fs['content-pin']['hidden'],
        '两张表单没有共用同一张页面级票据');
});

check('时间预填成 datetime-local 格式，置顶由两个按钮枚举而非复选框', function () use ($data, $allowed, $cap, $ticket, $forms): void {
    $blocks = ContentAdminBlocks::build('live', $data, $ticket, $allowed, null, false, 'pl-a', $cap);
    $fs = $forms($blocks);
    $at = '';
    $buttons = [];
    foreach ((array) ($fs['content-schedule']['fields'] ?? []) as $f) {
        if (is_array($f) && ($f['name'] ?? '') === 'publish_at') {
            $at = (string) ($f['value'] ?? '');
            truthy(($f['type'] ?? '') === 'datetime-local', '时间输入不是 datetime-local');
        }
    }
    truthy($at === '2026-11-14T08:30', '时间预填格式不对：' . $at);
    foreach ((array) ($fs['content-pin']['fields'] ?? []) as $f) {
        if (!is_array($f)) continue;
        truthy(($f['type'] ?? '') !== 'checkbox' && ($f['tag'] ?? '') !== 'checkbox',
            '置顶状态依赖复选框，模板不输出 checked 会误取消置顶');
        if (($f['tag'] ?? '') === 'submit') $buttons[] = (string) ($f['value'] ?? '');
    }
    truthy($buttons === ['pin:on', 'pin:off'], '置顶状态没有由两个按钮枚举：' . implode(',', $buttons));
    truthy(str_contains(json_encode($blocks, JSON_UNESCAPED_UNICODE) ?: '', '已置顶，权重 42'),
        '面板没有如实显示当前置顶状态');
});

check('找不到 / 没票据 / 没权限：都明说原因，绝不渲染假入口', function () use ($data, $allowed, $ticket, $cap, $forms): void {
    $miss = ContentAdminBlocks::build('live', $data, $ticket, $allowed, null, false, 'zzz', $cap);
    truthy(str_contains(json_encode($miss, JSON_UNESCAPED_UNICODE) ?: '', '没有加载设置面板'),
        'focus 未命中时没有明说「未加载」');
    truthy($forms($miss) === [], 'focus 未命中却渲染了指向别处的表单');

    $noTicket = ContentAdminBlocks::build('live', $data, '', $allowed, null, false, 'pl-a', $cap);
    truthy($forms($noTicket) === [], '票据为空仍渲染必然 401 的按钮');
    truthy(str_contains(json_encode($noTicket, JSON_UNESCAPED_UNICODE) ?: '', '写入口当前不可用'),
        '票据失败没有说明原因');

    $noCap = ContentAdminBlocks::build('live', $data, $ticket, $allowed, null, false, 'pl-a',
        ['can_schedule' => false, 'can_pin' => false]);
    truthy($forms($noCap) === [], '无权限仍渲染表单');
    $text = json_encode($noCap, JSON_UNESCAPED_UNICODE) ?: '';
    truthy(str_contains($text, 'content.publish') && str_contains($text, 'content.edit'),
        '没有分别说明缺哪个权限点');
});
