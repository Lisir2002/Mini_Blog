<?php
declare(strict_types=1);
namespace Wx\Pages\Acl;

/**
 * 用户组面板的只读区块 —— 从 AclPanels 拆出，避免单文件越过职责预算。
 *
 * 组只是角色来源，不是第二套权限表：成员通过组获得的角色与自身角色取并集，
 * 所以这一页显示的是「谁因此多了哪些角色」，而不是「谁被单独授了什么权」。
 * `users.view` 在这里作为读路径被消费：加成员之前先看清 user:<id> 是谁。
 */
final class AclGroupPanels
{
    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    public static function groups(array $data): array
    {
        if ($data['groups'] === null) {
            return [self::warn('acl-groups-broken', '分组清单读不到',
                (string) ($data['groups_fail'] ?? '未知原因'), 'unavailable')];
        }
        $blocks = [];
        $map = (array) ($data['groups']['groups'] ?? []);
        if ($map === []) {
            $blocks[] = self::warn('acl-groups-empty', '还没有分组',
                '组只是角色来源：成员通过组获得的角色与自身角色取并集，不复制授权表。'
                . '空分组不等于「没有权限」—— 角色授权在 /console/roles。', 'empty');
        } else {
            $rows = [];
            foreach ($map as $key => $row) {
                if (!is_array($row)) continue;
                $roles = array_map('strval', (array) ($row['roles'] ?? []));
                $members = [];
                foreach ((array) ($row['members'] ?? []) as $m) {
                    if (is_array($m)) $members[] = (string) ($m['subject_type'] ?? '?')
                        . ':' . (string) ($m['subject_id'] ?? '');
                }
                $rows[] = ['k' => (string) $key . ' · ' . (string) ($row['name'] ?? ''),
                    'v' => count($roles) . ' 个角色 / ' . count($members) . ' 个成员',
                    'ok' => true,
                    'note' => '角色：' . ($roles === [] ? '（无）' : implode('、', $roles))
                        . ' · 成员：' . ($members === [] ? '（无）' : implode('、', $members))
                        . ' · sort ' . (int) ($row['sort'] ?? 0)];
            }
            $blocks[] = ['type' => 'table', 'id' => 'acl-groups',
                'title' => '用户组 · ' . count($rows) . ' 个', 'rows' => $rows];
        }
        $warnings = (array) ($data['groups']['warnings'] ?? []);
        if ($warnings !== []) {
            $blocks[] = self::warn('acl-groups-warn', '展开分组时有告警',
                implode('；', array_map('strval', $warnings))
                . ' 常见于未知主体类型：这类成员不会给任何人带来角色。', 'inconsistent');
        }
        if ((string) ($data['user'] ?? '') !== '') $blocks[] = self::user($data);
        return $blocks;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function user(array $data): array
    {
        $state = (string) ($data['user_state'] ?? 'idle');
        if ($state === 'found') {
            $u = (array) ($data['user_view'] ?? []);
            return ['type' => 'notice', 'id' => 'acl-user', 'tone' => 'info', 'state' => 'found',
                'title' => 'user:' . (string) $data['user'] . ' = '
                    . (string) ($u['username'] ?? '?'),
                'value' => '显示名 ' . (string) ($u['display_name'] ?? '?')
                    . ' · 角色 ' . (string) ($u['role'] ?? '?')
                    . ' · 状态 ' . (string) ($u['status'] ?? '?')
                    . '。加成员时请写成 user:' . (string) $data['user'] . '。'];
        }
        return self::warn('acl-user-missing', 'user:' . (string) $data['user'] . ' 查不到',
            (string) ($data['user_error'] ?? 'users.view 返回查无此人')
            . ' 接口不校验成员是否存在，别把这个 ID 写进分组。',
            $state === 'missing' ? 'missing' : 'unavailable');
    }

    /** @return array<string, mixed> */
    private static function warn(string $id, string $title, string $value, string $state): array
    {
        return ['type' => 'notice', 'id' => $id,
            'tone' => in_array($state, ['unavailable', 'missing', 'inconsistent', 'truncated'], true)
                ? 'warn' : 'info',
            'state' => $state, 'title' => $title, 'value' => $value];
    }
}
