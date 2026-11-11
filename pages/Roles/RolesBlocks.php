<?php
declare(strict_types=1);
namespace Wx\Pages\Roles;

/**
 * 角色授权区块 —— 纯函数，无 IO。
 *
 * 「直接许可」与「有效许可」必须分开显示：继承语义是并集，在子角色上撤销
 * 撤不掉父角色给的许可。只显示一个合并后的集合，会让管理员以为撤销生效了。
 * 没有 permission.manage 时只渲染表格，不渲染点了必然 403 的按钮。
 */
final class RolesBlocks
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $error
     * @return list<array<string, mixed>>
     */
    public static function build(array $data, string $ticket, bool $canManage,
        bool $showCatalog, string $notice, ?array $error): array
    {
        $blocks = [];
        if ($notice !== '') {
            $blocks[] = ['type' => 'notice', 'id' => 'roles-notice', 'tone' => 'ok',
                'state' => 'done', 'title' => '已执行', 'value' => $notice];
        }
        if ($error !== null) {
            $blocks[] = ['type' => 'notice', 'id' => 'roles-error', 'tone' => 'warn',
                'state' => 'failed', 'title' => '没有执行',
                'value' => (string) ($error['message'] ?? '请稍后重试。')];
        }
        $blocks[] = self::summary($data);
        $blocks[] = ['type' => 'links', 'id' => 'roles-nav', 'title' => '相关', 'items' => [
            ['label' => '权限点清单', 'href' => '/console/roles?catalog=1', 'active' => $showCatalog,
             'note' => (string) count((array) ($data['catalog'] ?? [])) . ' 个'],
            ['label' => '紧凑视图', 'href' => '/console/roles', 'active' => !$showCatalog],
            ['label' => '用户管理', 'href' => '/console/users'],
            ['label' => '内容管理', 'href' => '/console/content'],
        ]];

        foreach ((array) ($data['roles'] ?? []) as $name => $role) {
            if (!is_array($role)) continue;
            $blocks[] = self::card((string) $name, $role);
            if ($canManage) $blocks[] = self::form((string) $name, $role, $ticket);
        }
        if ($showCatalog) $blocks[] = self::catalog((array) ($data['catalog'] ?? []));
        return $blocks;
    }

    /**
     * @param array<string, mixed> $role
     * @return array<string, mixed>
     */
    private static function card(string $name, array $role): array
    {
        $direct = array_values(array_map('strval', (array) ($role['permissions'] ?? [])));
        $parents = array_values(array_map('strval', (array) ($role['parents'] ?? [])));
        $effective = array_values(array_map('strval', (array) ($role['effective_permissions'] ?? $direct)));
        $inherited = array_values(array_diff($effective, $direct));

        return ['type' => 'table', 'id' => 'role-' . $name,
            'title' => $name . ' · ' . (string) ($role['description'] ?? ''), 'rows' => [
                ['k' => '直接许可', 'v' => $direct === [] ? '(无)' : implode(', ', $direct),
                 'ok' => $direct !== [], 'note' => '由本站显式授予，可撤销'],
                ['k' => '继承自', 'v' => $parents === [] ? '(无)' : implode(', ', $parents),
                 'ok' => true, 'note' => '子角色继承父角色的全部许可'],
                ['k' => '有效许可', 'v' => $effective === [] ? '(无)' : implode(', ', $effective),
                 'ok' => true,
                 'note' => $inherited === [] ? '与直接许可一致'
                     : '其中 ' . implode(', ', $inherited) . ' 来自继承，撤销要改父角色'],
            ]];
    }

    /**
     * @param array<string, mixed> $role
     * @return array<string, mixed>
     */
    private static function form(string $name, array $role, string $ticket): array
    {
        $direct = implode(', ', array_map('strval', (array) ($role['permissions'] ?? [])));
        $parents = implode(', ', array_map('strval', (array) ($role['parents'] ?? [])));
        return [
            'type' => 'form',
            'title' => '修改 ' . $name,
            'body' => '直接许可：' . ($direct === '' ? '(无)' : $direct)
                . ' · 父角色：' . ($parents === '' ? '(无)' : $parents),
            'action' => '/console/roles', 'method' => 'post',
            'hidden' => [
                ['name' => '_form_ticket', 'value' => $ticket],
                ['name' => 'role', 'value' => $name],
            ],
            // 票据签发失败时不给字段：提交必然被守卫挡回，不如不显示。
            'fields' => $ticket === '' ? [] : [
                ['tag' => 'input', 'id' => 'perm-' . $name, 'name' => 'permission', 'type' => 'text',
                 'placeholder' => '权限点，如 content.edit 或 *'],
                ['tag' => 'input', 'id' => 'parent-' . $name, 'name' => 'parent', 'type' => 'text',
                 'placeholder' => '父角色，如 editor'],
                ['tag' => 'input', 'id' => 'confirm-' . $name, 'name' => 'confirm_role', 'type' => 'text',
                 'placeholder' => '改 admin 或动 * 时手写角色名'],
                ['tag' => 'submit', 'name' => 'action', 'value' => 'grant', 'label' => '授予'],
                ['tag' => 'submit', 'name' => 'action', 'value' => 'revoke', 'label' => '撤销直接许可'],
                ['tag' => 'submit', 'name' => 'action', 'value' => 'set_parent', 'label' => '设父角色'],
                ['tag' => 'submit', 'name' => 'action', 'value' => 'remove_parent', 'label' => '解除继承'],
            ],
            'note' => $ticket === ''
                ? '表单票据签发失败，暂不显示写操作。'
                : '继承是并集：撤销只删本角色的直接许可，父角色给的不受影响。'
                    . '改 admin 角色或动通配符 * 需要手写角色名确认。',
        ];
    }

    /** @param array<string, mixed> $catalog @return array<string, mixed> */
    private static function catalog(array $catalog): array
    {
        $rows = [];
        foreach ($catalog as $perm => $info) {
            if (!is_array($info)) continue;
            $rows[] = ['k' => (string) $perm, 'v' => (string) ($info['system'] ?? ''),
                'ok' => true, 'note' => (string) ($info['description'] ?? '')];
        }
        return ['type' => 'table', 'id' => 'roles-catalog',
            'title' => '可授权的权限点（' . count($rows) . '）', 'rows' => $rows];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function summary(array $data): array
    {
        if ((string) ($data['error'] ?? '') !== '') {
            return ['type' => 'notice', 'id' => 'roles-summary', 'tone' => 'warn',
                'state' => 'unavailable', 'title' => '角色与许可不可用',
                'value' => (string) $data['error']];
        }
        $total = (int) ($data['total'] ?? 0);
        if ($total === 0) {
            return ['type' => 'notice', 'id' => 'roles-summary', 'tone' => 'warn',
                'state' => 'empty', 'title' => '没有角色',
                'value' => '角色由权限系统播种，一个都没有说明播种没有跑过。'];
        }
        return ['type' => 'notice', 'id' => 'roles-summary', 'tone' => 'info', 'state' => 'ok',
            'title' => '角色与许可',
            'value' => $total . ' 个角色，' . count((array) ($data['catalog'] ?? []))
                . ' 个可授权权限点，继承深度上限 ' . (int) ($data['max_depth'] ?? 0) . ' 层。'];
    }
}
