<?php
declare(strict_types=1);
namespace Wx\Pages\Acl;

/**
 * ACL 页的区块编排 —— 四个面板，一次只展开一个。
 *
 * 面板切换保留 slug / user / group：管理员在「查账号」和「加成员」之间来回跳时，
 * 不该把刚查到的 ID 丢掉。切换是 GET 链接，不依赖脚本，可收藏、可回退。
 */
final class AclBlocks
{
    private const LABELS = [
        'acl' => '资源 ACL', 'groups' => '用户组',
        'tree' => '角色继承树', 'overrides' => '覆盖台账',
    ];

    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    public static function build(array $data): array
    {
        if (($data['ok'] ?? false) !== true) {
            return [[
                'type' => 'notice', 'id' => 'acl-error', 'tone' => 'warn',
                'state' => (int) ($data['status'] ?? 503) === 422 ? 'bad-param' : 'unavailable',
                'title' => (int) ($data['status'] ?? 503) === 422 ? '查询条件不合法' : '授权数据不可用',
                'value' => (string) ($data['error'] ?? '未知原因'),
            ], self::boundary()];
        }
        $panel = (string) ($data['panel'] ?? 'acl');
        $blocks = array_merge([self::header()], AclNotice::build($data), [self::switcher($panel, $data)]);
        $blocks = array_merge($blocks, self::panel($panel, $data), AclForms::build($data));
        return array_merge($blocks, [self::nav(), self::boundary()]);
    }

    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    private static function panel(string $panel, array $data): array
    {
        return match ($panel) {
            'acl'       => AclPanels::acl($data),
            'groups'    => AclGroupPanels::groups($data),
            'tree'      => AclReadPanels::tree($data),
            default     => AclReadPanels::overrides($data),
        };
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function switcher(string $panel, array $data): array
    {
        $keep = [];
        foreach (['slug', 'user', 'group'] as $key) {
            $value = (string) ($data[$key] ?? '');
            if ($value !== '') $keep[$key] = $value;
        }
        $items = [];
        foreach (self::LABELS as $key => $label) {
            $items[] = ['label' => $label, 'active' => $key === $panel,
                'href' => '/console/acl?' . http_build_query($keep + ['panel' => $key])];
        }
        return ['type' => 'links', 'id' => 'acl-panels', 'title' => '选择视图', 'items' => $items];
    }

    /** @return array<string, mixed> */
    private static function header(): array
    {
        return ['type' => 'notice', 'id' => 'acl-scope', 'tone' => 'info', 'state' => 'scope',
            'title' => '资源 ACL · 用户组 · 角色树',
            'value' => 'permission.acl.list 没有按资源过滤的参数，只给最近 100 条；'
                . '所以先按 content.posts 选中一篇文章，再在页面层筛出它的条目。'
                . '页面声明 permission.read，改动在写入口现场问 permission.manage。'
                . '契约覆盖台账只读 —— 装配期动作不进网页。'];
    }

    /** @return array<string, mixed> */
    private static function nav(): array
    {
        return ['type' => 'links', 'id' => 'acl-nav', 'title' => '去别处', 'items' => [
            ['label' => '角色授权', 'href' => '/console/roles', 'note' => '角色许可与继承在这里改'],
            ['label' => '决策轨迹', 'href' => '/console/decisions', 'note' => '现场裁决为什么放行 / 拒绝'],
            ['label' => '用户管理', 'href' => '/console/users', 'note' => '账号在这里'],
            ['label' => '成员成长', 'href' => '/console/member', 'note' => '头衔不参与权限'],
        ]];
    }

    /** @return array<string, mixed> */
    private static function boundary(): array
    {
        return ['type' => 'notice', 'id' => 'acl-boundary', 'tone' => 'info', 'state' => 'boundary',
            'title' => '这一页的边界',
            'value' => 'permission.acl.set 是追加，没有编辑 / 删除单条 ACL 的接口；'
                . '显式 deny 优先级最高，新的 allow 不会抵消已有的 deny。'
                . 'acl.list 只给最近 100 条，这一页不是完整快照。'
                . '组只是角色来源，不新增决策层；颜色与排序只是展示属性。'
                . 'permission.override.grant 刻意不接（本机来源限制在 RequestGuard 层）。'
                . '不做批量导入、不做 ACL 影响面预演。'];
    }
}
