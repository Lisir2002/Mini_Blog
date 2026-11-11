<?php
declare(strict_types=1);
namespace Wx\Pages\Users;

/**
 * 用户管理区块 —— 纯函数，无 IO。
 *
 * 能看列表与能改账号是两件事：没有 user.manage 时只渲染只读表格，
 * 不渲染一个点了必然 403 的按钮。列表为空与读取失败也必须长得不一样。
 */
final class UsersBlocks
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $error
     * @return list<array<string, mixed>>
     */
    public static function build(array $data, string $ticket, bool $canManage,
        string $status, int $offset, string $notice, ?array $error): array
    {
        $blocks = [];
        if ($notice !== '') {
            $blocks[] = ['type' => 'notice', 'id' => 'users-notice', 'tone' => 'ok',
                'state' => 'done', 'title' => '已执行', 'value' => $notice];
        }
        if ($error !== null) {
            $blocks[] = ['type' => 'notice', 'id' => 'users-error', 'tone' => 'warn',
                'state' => 'failed', 'title' => '没有执行',
                'value' => (string) ($error['message'] ?? '请稍后重试。')];
        }
        $blocks[] = self::notice($data, $status, $offset);
        $blocks[] = ['type' => 'links', 'id' => 'users-filter', 'title' => '筛选', 'items' => [
            ['label' => '全部', 'href' => '/console/users', 'active' => $status === ''],
            ['label' => '正常', 'href' => '/console/users?status=active', 'active' => $status === 'active'],
            ['label' => '已禁用', 'href' => '/console/users?status=disabled', 'active' => $status === 'disabled'],
        ]];
        if (!empty($data['truncated'])) {
            $blocks[] = ['type' => 'links', 'id' => 'users-page', 'title' => '还有更多', 'items' => [[
                'label' => '下一页',
                'href' => '/console/users?offset=' . ($offset + UsersQuery::LIMIT)
                    . ($status !== '' ? '&status=' . rawurlencode($status) : ''),
                'note' => '从第 ' . ($offset + UsersQuery::LIMIT + 1) . ' 个开始',
            ]]];
        }

        $items = (array) ($data['items'] ?? []);
        if ($items === []) return $blocks;

        if (!$canManage) {
            $rows = [];
            foreach ($items as $user) $rows[] = self::row($user);
            $blocks[] = ['type' => 'table', 'id' => 'users-table', 'title' => '账号（只读）', 'rows' => $rows];
            return $blocks;
        }

        foreach ($items as $user) {
            if (is_array($user)) $blocks[] = self::form($user, $ticket);
        }
        return $blocks;
    }

    /** @param array<string, mixed> $user @return array<string, mixed> */
    private static function row(array $user): array
    {
        return ['k' => '#' . (int) ($user['id'] ?? 0) . ' ' . (string) ($user['username'] ?? ''),
            'v' => (string) ($user['role'] ?? '') . ' · ' . (string) ($user['status'] ?? ''),
            'note' => (string) ($user['created_at'] ?? '')];
    }

    /** @param array<string, mixed> $user @return array<string, mixed> */
    private static function form(array $user, string $ticket): array
    {
        $id = (int) ($user['id'] ?? 0);
        $active = (string) ($user['status'] ?? '') === 'active';
        $fields = [
            ['tag' => 'input', 'id' => 'role-' . $id, 'name' => 'role', 'type' => 'text',
             'placeholder' => '新角色，如 author'],
            ['tag' => 'input', 'id' => 'pwd-' . $id, 'name' => 'new_password', 'type' => 'password',
             'placeholder' => '重置口令（至少 8 位）'],
            ['tag' => 'submit', 'name' => 'action', 'value' => $active ? 'disable' : 'enable',
             'label' => $active ? '禁用' : '启用'],
            ['tag' => 'submit', 'name' => 'action', 'value' => 'set_role', 'label' => '改角色'],
            ['tag' => 'submit', 'name' => 'action', 'value' => 'reset_password', 'label' => '重置口令'],
        ];
        return [
            'type' => 'form',
            'title' => '#' . $id . ' ' . (string) ($user['username'] ?? '') . ' · '
                . (string) ($user['role'] ?? '') . ' · ' . (string) ($user['status'] ?? ''),
            'body' => '显示名：' . ((string) ($user['display_name'] ?? '') ?: '(未设置)')
                . ' · 创建于 ' . (string) ($user['created_at'] ?? ''),
            'action' => '/console/users', 'method' => 'post',
            'hidden' => [
                ['name' => '_form_ticket', 'value' => $ticket],
                ['name' => 'user_id', 'value' => (string) $id],
            ],
            'fields' => $ticket === '' ? [] : $fields,
            'note' => $ticket === ''
                ? '表单票据签发失败，暂不显示写操作。'
                : '禁用与重置口令都会撤销该账号全部令牌；不能禁用自己，也不能移除最后一个管理员。',
        ];
    }

    /** @param array<string, mixed> $data */
    private static function notice(array $data, string $status, int $offset): array
    {
        if ((string) ($data['error'] ?? '') !== '') {
            return ['type' => 'notice', 'id' => 'users-summary', 'tone' => 'warn',
                'state' => 'unavailable', 'title' => '用户列表不可用',
                'value' => (string) $data['error']];
        }
        $total = (int) ($data['total'] ?? 0);
        $shown = (int) ($data['shown'] ?? 0);
        if ($total === 0) {
            return ['type' => 'notice', 'id' => 'users-summary', 'tone' => 'ok',
                'state' => 'empty', 'title' => '没有账号',
                'value' => $status === '' ? '站点上还没有任何账号。' : '当前筛选下没有账号。'];
        }
        $value = '共 ' . $total . ' 个账号，本页显示 ' . $shown . ' 个';
        if (!empty($data['truncated'])) $value .= '（未列全）';
        if ($offset > 0) $value .= '；当前从第 ' . ($offset + 1) . ' 个开始';
        return ['type' => 'notice', 'id' => 'users-summary', 'tone' => 'info',
            'state' => 'ok', 'title' => '账号列表', 'value' => $value . '。'];
    }
}
