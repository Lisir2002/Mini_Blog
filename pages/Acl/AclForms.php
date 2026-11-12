<?php
declare(strict_types=1);
namespace Wx\Pages\Acl;

/**
 * 两张写表单 —— 资源 ACL（追加一条规则）与用户组（七个动作）。
 *
 * 表单字段与 AclInput 的校验一一对应；特别是 subject 与 capability：
 * 存储层会把未知前缀静默解释成角色、把写错的能力当成永不命中的规则，
 * 所以 hint 里必须把允许的写法写全，而不是让人「随便填一个」。
 */
final class AclForms
{
    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    public static function build(array $data): array
    {
        $panel = (string) ($data['panel'] ?? '');
        if (!in_array($panel, ['acl', 'groups'], true)) return [];
        if (($data['can'] ?? false) !== true || (string) ($data['ticket'] ?? '') === '') {
            return [['type' => 'notice', 'id' => 'acl-no-write', 'tone' => 'info',
                'state' => 'permission-denied', 'title' => '写入口不可用',
                'value' => '改 ACL 与改分组要求 permission.manage：能看授权不等于能改授权。'
                    . '上面的只读视图仍然有效。']];
        }
        return $panel === 'acl' ? self::acl($data) : self::groups($data);
    }

    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    private static function acl(array $data): array
    {
        if ((string) ($data['slug'] ?? '') === '' || (string) ($data['post_state'] ?? '') !== 'found') {
            return [['type' => 'notice', 'id' => 'acl-form-wait', 'tone' => 'info',
                'state' => 'need-resource', 'title' => '先选中一篇文章',
                'value' => 'ACL 是资源级的：没有 type+id 就不知道在给谁设规则。'
                    . '上面选好文章之后这里才会出现表单。']];
        }
        $hidden = [
            ['name' => '_form_ticket', 'value' => (string) $data['ticket']],
            ['name' => 'panel', 'value' => 'acl'],
            ['name' => 'slug', 'value' => (string) $data['slug']],
        ];
        $form = (array) ($data['form'] ?? []);
        $fields = [];
        foreach ([
            ['subject', '主体：* / role:editor / user:1（默认 *）'],
            ['capability', '能力：* / content.read / content.*'],
            ['field', '字段维度：留空 = 整资源级；可填 body/title/status/pinned/weight'],
            ['confirm', '高危确认：整资源级 */* deny 时手写文章 slug'],
        ] as [$name, $hint]) {
            $fields[] = ['tag' => 'input', 'id' => 'acl-' . $name, 'name' => $name, 'type' => 'text',
                'value' => (string) ($form[$name] ?? ''), 'placeholder' => $hint];
        }
        $fields[] = ['tag' => 'submit', 'name' => 'allow', 'value' => '1', 'label' => '追加放行规则'];
        $fields[] = ['tag' => 'submit', 'name' => 'allow', 'value' => '0', 'label' => '追加显式拒绝（deny）'];

        return [['type' => 'form', 'id' => 'acl-form', 'title' => '给 ' . (string) $data['slug'] . ' 追加一条 ACL',
            'action' => '/console/acl?panel=acl&slug=' . rawurlencode((string) $data['slug']),
            'method' => 'post', 'hidden' => $hidden, 'fields' => $fields,
            'note' => 'permission.acl.set 是**追加**，没有编辑 / 删除单条规则的接口。'
                . '显式 deny 优先级最高，压过角色通配符与继承链；新加一条 allow 不会抵消已有的 deny。'
                . '列表只返回最近 100 条，超过会漏。']];
    }

    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    private static function groups(array $data): array
    {
        $hidden = [
            ['name' => '_form_ticket', 'value' => (string) $data['ticket']],
            ['name' => 'panel', 'value' => 'groups'],
        ];
        $form = (array) ($data['form'] ?? []);
        $fields = [];
        foreach ([
            ['group', '分组标识（必填，小写字母数字 . _ -）'],
            ['name', '显示名（upsert 用）'],
            ['description', '说明（upsert 用）'],
            ['color', '颜色（仅展示）'],
            ['sort', '排序，整数，越小越靠前'],
            ['role', '角色名（add_role / remove_role 用，须存在）'],
            ['subject', '成员 user:<数字 ID>（add_member / remove_member 用）'],
            ['confirm', '删除分组时原样手写分组标识'],
        ] as [$name, $hint]) {
            $fields[] = ['tag' => 'input', 'id' => 'acl-g-' . $name, 'name' => $name, 'type' => 'text',
                'value' => (string) ($form[$name] ?? ''), 'placeholder' => $hint];
        }
        foreach ([
            ['upsert', '新建 / 更新'], ['add_role', '加角色'], ['remove_role', '移除角色'],
            ['add_member', '加成员'], ['remove_member', '移除成员'], ['delete', '删除分组（需确认）'],
        ] as [$value, $label]) {
            $fields[] = ['tag' => 'submit', 'name' => 'op', 'value' => $value, 'label' => $label];
        }

        $lookup = ['type' => 'form', 'id' => 'acl-user-lookup', 'title' => '查一下这个账号是谁',
            'action' => '/console/acl', 'method' => 'get',
            'hidden' => [['name' => 'panel', 'value' => 'groups']],
            'fields' => [
                ['tag' => 'input', 'id' => 'acl-user', 'name' => 'user', 'type' => 'text',
                    'placeholder' => '数字账号 ID，如 1'],
                ['tag' => 'submit', 'name' => 'lookup', 'value' => '1', 'label' => '查询'],
            ],
            'note' => '走 users.view：加成员之前先确认 user:<id> 对应的是谁。'
                . '接口不校验成员是否存在，写错的主体会成为一条悬空绑定。'];

        return [$lookup, ['type' => 'form', 'id' => 'acl-group-form', 'title' => '改动分组',
            'action' => '/console/acl?panel=groups', 'method' => 'post',
            'hidden' => $hidden, 'fields' => $fields,
            'note' => '组只是角色来源：成员通过组获得的角色与自身角色取并集，不复制授权表、不新增决策层。'
                . '删除分组会一并清理成员与角色绑定，且没有撤销入口。'
                . '颜色与排序只是展示属性，不参与权限决策。']];
    }
}
