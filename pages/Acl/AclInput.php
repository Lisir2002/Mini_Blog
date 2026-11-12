<?php
declare(strict_types=1);
namespace Wx\Pages\Acl;

/**
 * 资源 ACL 与用户组的输入校验 —— 页面层必须先挡住接口会「静默解释错」的字段。
 *
 * 三个真实的坑：
 *  1. `PdoPermissionStore::parseSubject()` 对未知前缀一律当成 role：
 *     `bogus:foo` 会写成 `subject_type=role, subject_id=foo`，一条永远不命中的 ACL；
 *  2. `permission.acl.set` 的 capability 写错不报错，只是永不命中（`AclMatcher` 按字面 / 前缀匹配）；
 *  3. `setAcl` 是纯 INSERT，没有 update / delete：页面不能把「追加一条规则」说成「修改规则」，
 *     更不能用一条新的 allow 去「撤销」已有的 deny —— 显式 deny 优先级最高。
 *
 * 纯函数，无 IO：主体 / 角色 / 权限点的存在性由 AclAction 预查后以布尔量传进来。
 */
final class AclInput
{
    public const PANELS = ['acl', 'groups', 'tree', 'overrides'];
    public const FIELDS = ['', 'body', 'title', 'status', 'pinned', 'weight'];
    public const OPS = ['upsert', 'add_role', 'remove_role', 'add_member', 'remove_member', 'delete'];

    /** @return array{ok: true, operation: string, in: array<string, mixed>, detail: string, hint: string}
     *          |array{ok: false, message: string} */
    public static function normalize(string $panel, array $body, array $facts): array
    {
        return $panel === 'acl' ? self::acl($body, $facts) : self::group($body, $facts);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $facts 预查结果：role_ok / user_ok / post_ok / catalog / roles
     * @return array{ok: true, operation: string, in: array<string, mixed>, detail: string, hint: string}|array{ok: false, message: string}
     */
    private static function acl(array $body, array $facts): array
    {
        $slug = trim((string) ($body['slug'] ?? ''));
        $subject = trim((string) ($body['subject'] ?? ''));
        $cap = trim((string) ($body['capability'] ?? ''));
        $field = trim((string) ($body['field'] ?? ''));
        $allowRaw = (string) ($body['allow'] ?? '');
        if (!in_array($allowRaw, ['1', '0'], true)) {
            return self::no('放行 / 拒绝只能由两个按钮给出，不要直接构造 POST。');
        }
        if (($facts['post_ok'] ?? false) !== true) {
            return self::no('文章「' . $slug . '」读不到，无法给不存在的资源设 ACL。'
                . '接口对 id 不做存在性校验，写进去就是一条永远不命中的孤儿规则。');
        }
        if (!in_array($field, self::FIELDS, true)) {
            return self::no('field 只能是 ' . implode(' / ', array_slice(self::FIELDS, 1))
                . ' 或留空（整资源级）。写错的字段不会报错，但只对同名字段生效，等于没有。');
        }
        $subjectCheck = self::subject($subject, $facts);
        if ($subjectCheck !== '') return self::no($subjectCheck);
        $capCheck = self::capability($cap, (array) ($facts['catalog'] ?? []));
        if ($capCheck !== '') return self::no($capCheck);

        $allow = $allowRaw === '1';
        // 整资源级、对所有人、所有能力的显式 deny 会把管理员也挡在外面。
        // 这不是「配置严格」，是可能回不来的自锁，必须手写资源标识确认。
        if (!$allow && $subject === '*' && $cap === '*' && $field === ''
            && trim((string) ($body['confirm'] ?? '')) !== $slug) {
            return self::no('这是对所有人、所有能力、整篇文章的显式 deny —— '
                . 'deny 优先级最高，压过角色通配符与继承链，管理员也可能进不来。'
                . '请在「确认」里原样手写 ' . $slug . ' 再提交。');
        }
        $in = ['type' => 'post', 'id' => $slug, 'subject' => $subject,
            'capability' => $cap, 'allow' => $allow, 'field' => $field];
        $detail = '已为 ' . $slug . ' 追加一条 ACL：' . ($allow ? '放行' : '显式拒绝')
            . ' ' . $subject . ' 对 ' . $cap . ($field !== '' ? '（字段 ' . $field . '）' : '（整资源级）') . '。';
        $hint = '注意：permission.acl.set 是**追加**，不是编辑或撤销。'
            . '已有的显式 deny 优先级最高，新加一条 allow 不会把它抵消；要改规则得追加一条针对同一主体的新条目，'
            . '而系统没有删除单条 ACL 的接口。';
        return self::yes('permission.acl.set', $in, $detail, $hint);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $facts
     * @return array{ok: true, operation: string, in: array<string, mixed>, detail: string, hint: string}|array{ok: false, message: string}
     */
    private static function group(array $body, array $facts): array
    {
        $op = trim((string) ($body['op'] ?? ''));
        $key = trim((string) ($body['group'] ?? ''));
        if (!in_array($op, self::OPS, true)) {
            return self::no('分组动作只能由表单按钮给出（' . implode(' / ', self::OPS) . '）。');
        }
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $key) !== 1) {
            return self::no('分组标识只能用小写字母、数字、. _ -（最长 64）。');
        }
        $in = ['op' => $op, 'group' => $key];
        $role = trim((string) ($body['role'] ?? ''));
        $subject = trim((string) ($body['subject'] ?? ''));

        if (in_array($op, ['add_role', 'remove_role'], true)) {
            if (!isset(((array) ($facts['roles'] ?? []))[$role])) {
                return self::no('角色「' . $role . '」不存在。接口不校验角色名，'
                    . '写进去会成为一条指向空气的角色绑定，且不会报错。');
            }
            $in['role'] = $role;
        }
        if (in_array($op, ['add_member', 'remove_member'], true)) {
            if (preg_match('/^user:(\d+)$/', $subject, $m) !== 1) {
                return self::no('成员必须是 user:<数字 ID>，当前为「' . $subject . '」。');
            }
            if (($facts['user_ok'] ?? false) !== true) {
                return self::no('账号 user:' . $m[1] . ' 查不到（users.view）。'
                    . '接口不校验成员是否存在，写进去就是谁也解释不清的悬空成员。');
            }
            $in['subject'] = $subject;
        }
        if ($op === 'upsert') {
            foreach (['name' => 100, 'description' => 200, 'color' => 20] as $field => $max) {
                $value = trim((string) ($body[$field] ?? ''));
                if (self::length($value) > $max) return self::no($field . ' 最多 ' . $max . ' 字符。');
                $in[$field] = $value;
            }
            $sort = trim((string) ($body['sort'] ?? '0'));
            if (preg_match('/^-?\d+$/', $sort) !== 1) return self::no('sort 必须是整数。');
            $in['sort'] = (int) $sort;
        }
        if ($op === 'delete' && trim((string) ($body['confirm'] ?? '')) !== $key) {
            return self::no('删除分组会一并清理成员与角色绑定，且没有撤销入口。'
                . '请在「确认」里原样手写 ' . $key . ' 再提交。');
        }
        $detail = '分组「' . $key . '」执行 ' . $op . '。组只是角色来源：'
            . '成员通过组获得的角色与自身角色取并集，不复制授权表，也不新增决策层。';
        $hint = '显式 deny 与策略收紧仍优先于组带来的角色；颜色与排序只是展示属性，不参与权限决策。';
        return self::yes('permission.group', $in, $detail, $hint);
    }

    /**
     * 严格枚举主体：parseSubject() 会把任何未知前缀静默当成 role，
     * 所以页面必须先限定成 * / role:<已存在角色> / user:<已存在账号>。
     * @param array<string, mixed> $facts
     */
    private static function subject(string $subject, array $facts): string
    {
        if ($subject === '*') return '';
        if (preg_match('/^role:([a-z0-9._-]+)$/', $subject, $m) === 1) {
            return isset(((array) ($facts['roles'] ?? []))[$m[1]]) ? ''
                : '角色「' . $m[1] . '」不存在。主体只能写 * / role:<已存在角色> / user:<已存在账号>，'
                    . '其余前缀会被存储层静默解释成角色，写成一条永远不命中的规则。';
        }
        if (preg_match('/^user:(\d+)$/', $subject, $m) === 1) {
            return ($facts['user_ok'] ?? false) === true ? ''
                : '账号 user:' . $m[1] . ' 查不到（users.view），不能把 ACL 写给一个不存在的人。';
        }
        return '主体「' . $subject . '」格式非法。只能写 * / role:<角色名> / user:<数字 ID>；'
            . '存储层会把未知前缀静默解释成角色，不会报错。';
    }

    /** @param array<string, mixed> $catalog */
    private static function capability(string $cap, array $catalog): string
    {
        if ($cap === '') return 'capability 不能为空。';
        if ($cap === '*') return '';
        if (isset($catalog[$cap])) return '';
        if (str_ends_with($cap, '.*')) {
            $prefix = substr($cap, 0, -1);
            foreach (array_keys($catalog) as $known) {
                if (str_starts_with((string) $known, $prefix)) return '';
            }
        }
        return '能力「' . $cap . '」不在权限清单里，也不是合法的前缀通配（如 content.*）。'
            . '接口不校验：写错不会报错，只是永远不命中。';
    }

    private static function length(string $value): int
    {
        if (function_exists('mb_strlen')) return mb_strlen($value, 'UTF-8');
        return count(preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /** @param array<string, mixed> $in
     *  @return array{ok: true, operation: string, in: array<string, mixed>, detail: string, hint: string} */
    private static function yes(string $operation, array $in, string $detail, string $hint): array
    {
        return ['ok' => true, 'operation' => $operation, 'in' => $in,
            'detail' => $detail, 'hint' => $hint];
    }

    /** @return array{ok: false, message: string} */
    private static function no(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }
}
