<?php
declare(strict_types=1);
namespace Wx\Pages\Member;

/**
 * 三张「配置类」面板 —— 等级表、头衔目录、经验规则。
 *
 * 它们共同的陷阱是「空」：空等级表、空目录、没有规则、没权限看规则，
 * 在界面上都长得像「一切正常，只是没内容」。四个状态各给一句不同的说法，
 * 因为处置方式完全不同（去建 / 去查 / 改配 / 要权限）。
 */
final class MemberPanels
{
    /**
     * @param array<string, mixed>|null $payload
     * @return list<array<string, mixed>>
     */
    public static function levels(?array $payload, string $error): array
    {
        if ($payload === null) return [self::broken('member-levels', '等级表不可用', $error)];
        $rows = [];
        foreach ((array) ($payload['levels'] ?? []) as $row) {
            if (!is_array($row)) continue;
            $rows[] = ['k' => 'Lv.' . (int) ($row['level'] ?? 0) . ' ' . (string) ($row['name'] ?? ''),
                'v' => '≥ ' . (int) ($row['min_xp'] ?? 0) . ' 经验',
                'ok' => true, 'note' => (string) ($row['color'] ?? '')];
        }
        if ($rows === []) {
            return [['type' => 'notice', 'id' => 'member-levels-empty', 'tone' => 'warn',
                'state' => 'unconfigured', 'title' => '等级表是空的',
                'value' => '一条等级门槛都没有。此时所有成员都会被解释成 Lv.1（LevelCurve 的兜底），'
                    . '页面看起来仍然正常 —— 这正是危险之处：没人能升级，也没有任何报错。']];
        }

        $blocks = [['type' => 'table', 'id' => 'member-levels', 'title' => '等级表 · ' . count($rows) . ' 档',
            'rows' => $rows]];
        $warn = self::levelGaps((array) ($payload['levels'] ?? []));
        if ($warn !== '') {
            $blocks[] = ['type' => 'notice', 'id' => 'member-levels-warn', 'tone' => 'warn',
                'state' => 'inconsistent', 'title' => '等级表有断点', 'value' => $warn];
        }
        return $blocks;
    }

    /**
     * 跳号与重复门槛都是「结构合法、语义可疑」的配置：接口不报错，成员却会卡在同一档。
     *
     * @param list<mixed> $rows
     */
    private static function levelGaps(array $rows): string
    {
        $levels = [];
        $thresholds = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $levels[] = (int) ($row['level'] ?? 0);
            $xp = (int) ($row['min_xp'] ?? 0);
            $thresholds[$xp] = ($thresholds[$xp] ?? 0) + 1;
        }
        sort($levels);
        $missing = [];
        for ($i = 1; $i <= ($levels === [] ? 0 : max($levels)); $i++) {
            if (!in_array($i, $levels, true)) $missing[] = 'Lv.' . $i;
        }
        $dup = [];
        foreach ($thresholds as $xp => $n) {
            if ($n > 1) $dup[] = $xp . ' 经验（' . $n . ' 档）';
        }
        $notes = [];
        if ($missing !== []) {
            $notes[] = '缺少 ' . implode('、', $missing) . '：这些等级永远不会出现，'
                . '经验够了会直接跳到下一档存在的那一级。';
        }
        if ($dup !== []) {
            $notes[] = '门槛重复于 ' . implode('、', $dup) . '：同分时只有序号大的那档生效，'
                . '另一档等于不存在。';
        }
        return implode(' ', $notes);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return list<array<string, mixed>>
     */
    public static function titles(?array $payload, string $error): array
    {
        if ($payload === null) return [self::broken('member-titles', '头衔目录不可用', $error)];
        $rows = [];
        $hidden = 0;
        foreach ((array) ($payload['titles'] ?? []) as $row) {
            if (!is_array($row)) continue;
            $visible = (bool) ($row['visible'] ?? false);
            if (!$visible) $hidden++;
            $minLevel = (int) ($row['min_level'] ?? 0);
            $rows[] = ['k' => (string) ($row['name'] ?? '') . ' (' . (string) ($row['title_key'] ?? '') . ')',
                'v' => $visible ? '可见' : '隐藏',
                'ok' => $visible,
                'note' => $minLevel > 0 ? 'Lv.' . $minLevel . ' 起自动获得' : '仅手动授予'];
        }
        if ($rows === []) {
            return [['type' => 'notice', 'id' => 'member-titles-empty', 'tone' => 'warn',
                'state' => 'empty', 'title' => '头衔目录是空的',
                'value' => '没有任何头衔定义。成员档案里的头衔字段会是空数组 —— '
                    . '这与「有头衔但都是隐藏的」不同，后者在下面单独说明。']];
        }

        $blocks = [['type' => 'table', 'id' => 'member-titles', 'title' => '头衔目录 · ' . count($rows) . ' 个',
            'rows' => $rows]];
        if ($hidden > 0) {
            $blocks[] = ['type' => 'notice', 'id' => 'member-titles-hidden', 'tone' => 'info',
                'state' => 'partial', 'title' => $hidden . ' 个头衔是隐藏的',
                'value' => '公开接口 member.titles 默认只返回可见条目，所以站点上、主题里都看不到这 '
                    . $hidden . ' 个。本页显式带 include_hidden=1 —— 后台看不见自己建的头衔，'
                    . '会被当成删除成功。'];
        }
        return $blocks;
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return list<array<string, mixed>>
     */
    public static function rules(?array $payload, bool $canRead, string $error): array
    {
        if (!$canRead) {
            return [['type' => 'notice', 'id' => 'member-rules-denied', 'tone' => 'info',
                'state' => 'permission-denied', 'title' => '看不到经验规则',
                'value' => '读取规则要求 member.xp.rule.manage：规则数值（单次多少、每天上限）就是运营策略。'
                    . '注意这不是「没有规则」—— 这一点本页现在无法回答，别把它读成空。']];
        }
        if ($payload === null) return [self::broken('member-rules', '经验规则不可用', $error)];
        $rows = [];
        foreach ((array) ($payload['rules'] ?? []) as $row) {
            if (!is_array($row)) continue;
            $cap = (int) ($row['daily_cap'] ?? 0);
            $on = (bool) ($row['enabled'] ?? false);
            $rows[] = ['k' => (string) ($row['action'] ?? ''), 'v' => $on ? '启用' : '停用',
                'ok' => $on,
                'note' => '+' . (int) ($row['amount'] ?? 0) . ' / 次 · 每日上限 '
                    . ($cap > 0 ? $cap : '不限（0）') . ' · ' . (string) ($row['description'] ?? '')];
        }
        if ($rows === []) {
            return [['type' => 'notice', 'id' => 'member-rules-empty', 'tone' => 'warn',
                'state' => 'empty', 'title' => '一条经验规则都没有',
                'value' => '此时任何发经验调用都必须显式传 amount，否则会被拒绝（42201）。'
                    . '内容 / 评论事件触发的自动发经验也会因为「没有规则」而留痕失败 —— 不会报错给用户。']];
        }
        return [['type' => 'table', 'id' => 'member-rules', 'title' => '经验规则 · ' . count($rows) . ' 条',
            'rows' => $rows]];
    }

    /** @return array<string, mixed> */
    private static function broken(string $id, string $title, string $error): array
    {
        return ['type' => 'notice', 'id' => $id . '-error', 'tone' => 'warn', 'state' => 'unavailable',
            'title' => $title, 'value' => $error === '' ? '接口没有返回数据，也没有给出原因。' : $error];
    }
}
