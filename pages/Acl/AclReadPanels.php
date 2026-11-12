<?php
declare(strict_types=1);
namespace Wx\Pages\Acl;

/**
 * 角色继承树与契约覆盖台账 —— 两个只读面板。
 *
 * 覆盖台账刻意**不提供写入口**：permission.override.grant 是装配期动作，
 * 默认仅本机来源（40302）、要求 system.contract.override；页面直调执行者会
 * 绕过 RequestGuard 的本机来源检查。与安装 / 升级 / 回滚只在命令行同一取舍。
 */
final class AclReadPanels
{
    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    public static function tree(array $data): array
    {
        if ($data['tree'] === null) {
            return [self::warn('acl-tree-broken', '角色树读不到',
                (string) ($data['tree_fail'] ?? '未知原因'), 'unavailable')];
        }
        $rows = [];
        foreach ((array) ($data['tree']['roles'] ?? []) as $name => $row) {
            if (!is_array($row)) continue;
            $parents = array_map('strval', (array) ($row['parents'] ?? []));
            $ancestors = array_map('strval', (array) ($row['ancestors'] ?? []));
            $effective = array_map('strval', (array) ($row['effective_permissions'] ?? []));
            $rows[] = ['k' => (string) $name,
                'v' => count($effective) . ' 个有效许可 · 深度上限 '
                    . (int) ($data['tree']['max_depth'] ?? 0),
                'ok' => true,
                'note' => '父角色：' . ($parents === [] ? '（无）' : implode('、', $parents))
                    . ' · 祖先：' . ($ancestors === [] ? '（无）' : implode('、', $ancestors))
                    . ' · 许可：' . ($effective === [] ? '（无）' : implode('、', $effective))];
        }
        $blocks = $rows === []
            ? [self::warn('acl-tree-empty', '角色树是空的',
                '拔库回退时接口返回空图并带 fallback 告警 —— 这与「站点没有角色」不是一回事。', 'empty')]
            : [['type' => 'table', 'id' => 'acl-tree',
                'title' => '角色继承树 · ' . count($rows) . ' 个角色', 'rows' => $rows]];
        $warnings = (array) ($data['tree']['warnings'] ?? []);
        if ($warnings !== []) {
            $blocks[] = self::warn('acl-tree-warn', '展开时有告警',
                implode('；', array_map('strval', $warnings))
                . ' 深度截断时继承链可能没展开完，有效许可集要以决策器为准。', 'inconsistent');
        }
        $blocks[] = self::warn('acl-tree-note', '继承是并集',
            '直接许可 ∪ 继承来的许可；在子角色上撤销撤不掉父角色给的许可。'
            . '显式 deny 与策略收紧的优先级不受继承影响。', 'boundary');
        return $blocks;
    }

    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    public static function overrides(array $data): array
    {
        if ($data['overrides'] === null) {
            return [self::warn('acl-overrides-broken', '覆盖台账读不到',
                (string) ($data['overrides_fail'] ?? '未知原因'), 'unavailable')];
        }
        $raw = (array) $data['overrides'];
        $on = (bool) ($raw['require_grant'] ?? false);
        $blocks = [self::warn('acl-require-grant',
            $on ? '覆盖必须先授权：已启用' : '覆盖必须先授权：未启用',
            $on
                ? 'require_grant=true：未在台账里登记的覆盖会被标记 unauthorized，只留痕不生效。'
                : 'require_grant=false：所有覆盖都标记 authorized=true，保持「只留痕」语义。'
                    . '这不是「没有未授权覆盖」，是压根没开校验。',
            $on ? 'boundary' : 'unconfigured')];

        $overrides = array_values(array_filter((array) ($raw['overrides'] ?? []), 'is_array'));
        $unauthorized = array_values(array_filter((array) ($raw['unauthorized'] ?? []), 'is_array'));
        $rows = [];
        foreach ($overrides as $row) {
            $rows[] = ['k' => (string) ($row['contract'] ?? '?'),
                'v' => (bool) ($row['authorized'] ?? false) ? '已授权' : '未授权',
                'ok' => (bool) ($row['authorized'] ?? false),
                'note' => (string) ($row['from'] ?? '?') . ' → ' . (string) ($row['to'] ?? '?')
                    . ' · priority ' . (string) ($row['priority'] ?? '?')];
        }
        if ($rows !== []) {
            $blocks[] = ['type' => 'table', 'id' => 'acl-overrides',
                'title' => '装配期覆盖 · ' . count($rows) . ' 条', 'rows' => $rows];
        }
        $blocks[] = self::warn('acl-unauthorized',
            $unauthorized === [] ? '没有未授权的覆盖' : count($unauthorized) . ' 条覆盖未获授权',
            $unauthorized === []
                ? '当前所有覆盖都通过了授权校验（或 require_grant 未启用）。'
                : '这些覆盖只留痕、不生效。登记授权需要 permission.override.grant，'
                    . '本页刻意不提供写入口（见下方边界）。',
            $unauthorized === [] ? 'empty' : 'inconsistent');

        $grants = array_values(array_filter((array) ($raw['grants'] ?? []), 'is_array'));
        if ($grants !== []) {
            $grows = [];
            foreach ($grants as $row) {
                $grows[] = ['k' => (string) ($row['contract'] ?? '?'),
                    'v' => (int) ($row['active'] ?? 0) === 1 ? '生效' : '已撤销',
                    'ok' => (int) ($row['active'] ?? 0) === 1,
                    'note' => (string) ($row['owner'] ?? '?')];
            }
            $blocks[] = ['type' => 'table', 'id' => 'acl-grants',
                'title' => '授权台账 · ' . count($grows) . ' 条', 'rows' => $grows];
        }
        $blocks[] = self::warn('acl-no-grant-write', '为什么这里没有授权按钮',
            'permission.override.grant 是装配期动作：默认仅本机来源（40302）、要求 '
            . 'system.contract.override，且执行者会再次复核。页面直调执行者会绕过 RequestGuard '
            . '的本机来源检查 —— 把它接进网页等于把装配权限搬进后台。'
            . '与安装 / 升级 / 回滚一样，这一条刻意留在命令行。', 'boundary');
        return $blocks;
    }

    /** @return array<string, mixed> */
    private static function warn(string $id, string $title, string $value, string $state): array
    {
        return ['type' => 'notice', 'id' => $id,
            'tone' => in_array($state, ['unavailable', 'inconsistent', 'unconfigured', 'empty'], true)
                ? 'warn' : 'info',
            'state' => $state, 'title' => $title, 'value' => $value];
    }
}
