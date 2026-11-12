<?php
declare(strict_types=1);
namespace Wx\Pages\Terms;

/**
 * 分类与标签的输入校验 —— 页面层先挡住接口会「静默写错」的四类输入。
 *
 * 四个真实的坑：
 *  1. `content.terms.manage` 同 kind 下 slug 重复返回 40911，不是静默复用 ——
 *     复用会把「新建分类」变成「把文章挂到别人的分类上」；
 *  2. kind 与 slug 不可修改（改 slug 会让所有旧链接 404），所以 update 只给
 *     name / description，页面也不提供改 slug 的字段；
 *  3. `content.attach` 是**整体替换**：提交的数组就是最终集合，留空是清空，
 *     不是「再追加一个标签」。清空要手写文章 slug 确认；
 *  4. 清单读不到（50101 / 接口异常）时所有存在性校验都失败 —— 这正是 fail-closed，
 *     不能让「不知道有哪些术语」退化成「随便写一个让接口去判」。
 *
 * 纯函数，无 IO：术语清单与文章可读性由 TermsAction 预查后传进来。
 */
final class TermsInput
{
    public const OPS = ['create_category', 'create_tag', 'term_update', 'term_delete', 'attach'];
    public const KINDS = ['category', 'tag'];

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $facts terms_ok / post_ok / known / attached
     * @return array{ok: true, op: string, operation: string, in: array<string, mixed>, detail: string, hint: string}
     *          |array{ok: false, message: string}
     */
    public static function normalize(array $body, array $facts): array
    {
        $op = trim((string) ($body['op'] ?? ''));
        if (!in_array($op, self::OPS, true)) {
            return self::no('操作只能是 ' . implode(' / ', self::OPS)
                . '，当前为「' . ($op === '' ? '(空)' : $op) . '」。不要直接构造 POST。');
        }
        if (($facts['terms_ok'] ?? false) !== true) {
            return self::no('术语清单读不到，无法确定哪些术语存在。'
                . '内存降级模式下接口返回 50101，不是「还没有分类」；此刻不写入任何术语。');
        }
        $known = (array) ($facts['known'] ?? []);
        if (str_starts_with($op, 'create_')) return self::create($op, $body, $known);
        if ($op === 'term_update') return self::update($body, $known);
        if ($op === 'term_delete') return self::delete($body, $known);
        return self::attach($body, $facts, $known);
    }

    /** @param array<string, mixed> $body @param array<string, array<string, mixed>> $known */
    private static function create(string $op, array $body, array $known): array
    {
        $kind = $op === 'create_category' ? 'category' : 'tag';
        // 术语标识叫 term_slug，不与文章 slug 撞名：否则 PRG 回显会把
        // 「新建了一个叫 foo 的标签」误当成「选中了文章 foo」。
        $slug = trim((string) ($body['term_slug'] ?? ''));
        $name = trim((string) ($body['name'] ?? ''));
        $desc = trim((string) ($body['description'] ?? ''));
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,190}$/', $slug) !== 1) {
            return self::no('术语 slug 只能用小写字母、数字与连字符，最长 191 位。');
        }
        if ($name === '' || TermsRefs::length($name) > 60) {
            return self::no('显示名称不能为空，且不能超过 60 字（按字符算，不是字节）。');
        }
        if (TermsRefs::length($desc) > 200) return self::no('说明不能超过 200 字。');
        if (isset($known[$kind . ':' . $slug])) {
            return self::no('术语「' . $kind . ':' . $slug . '」已经存在。'
                . '接口会返回 40911 而不是复用它 —— 复用会把新建变成把文章挂到别人的术语上。'
                . 'kind 与 slug 都不可改，需要换标识就新建一个再迁移。');
        }
        return self::yes($op, 'content.terms.manage',
            ['action' => 'create', 'kind' => $kind, 'slug' => $slug, 'name' => $name, 'description' => $desc],
            '新建 ' . $kind . ':' . $slug . '（' . $name . '）。',
            'kind 与 slug 创建后不可修改：改 slug 会让所有旧链接 404。');
    }

    /** @param array<string, mixed> $body @param array<string, array<string, mixed>> $known */
    private static function update(array $body, array $known): array
    {
        $term = self::term($body, $known);
        if (!is_array($term)) return self::no((string) $term);
        $name = trim((string) ($body['name'] ?? ''));
        $desc = trim((string) ($body['description'] ?? ''));
        if ($name === '' && $desc === '') {
            return self::no('改名至少要给名称或说明中的一个；接口没有可改字段时会返回 updated=false。');
        }
        if ($name !== '' && TermsRefs::length($name) > 60) return self::no('显示名称不能超过 60 字。');
        if (TermsRefs::length($desc) > 200) return self::no('说明不能超过 200 字。');
        // 只改说明时不能把空 name 传下去：存储层 assertName('') 会抛 422，
        // 而「清空说明」是合法的，所以 description 恒传、name 只在非空时传。
        $in = ['action' => 'update', 'id' => (int) $term['id'], 'description' => $desc];
        if ($name !== '') $in['name'] = $name;
        return self::yes('term_update', 'content.terms.manage', $in,
            '更新术语 ' . (string) $term['kind'] . ':' . (string) $term['slug'] . ' 的名称 / 说明。',
            '只改 name 与 description；kind 与 slug 不可改，需要换标识就新建再迁移。');
    }

    /** @param array<string, mixed> $body @param array<string, array<string, mixed>> $known */
    private static function delete(array $body, array $known): array
    {
        $term = self::term($body, $known);
        if (!is_array($term)) return self::no((string) $term);
        $slug = (string) $term['slug'];
        if (trim((string) ($body['confirm'] ?? '')) !== $slug) {
            return self::no('删除术语会同时清掉它下面所有文章的绑定关系，且没有撤销入口。'
                . '请在确认框里原样手写 ' . $slug . ' 再提交。');
        }
        return self::yes('term_delete', 'content.terms.manage',
            ['action' => 'delete', 'id' => (int) $term['id']],
            '删除术语 ' . (string) $term['kind'] . ':' . $slug . '。',
            '删除在事务里一并清理关系表，不留孤儿行；回报里的 relations_removed 是真正被清掉的绑定数。');
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $facts
     * @param array<string, array<string, mixed>> $known
     */
    private static function attach(array $body, array $facts, array $known): array
    {
        $slug = trim((string) ($body['slug'] ?? ''));
        if ($slug === '') return self::no('文章 slug 缺失，无法挂载术语。');
        if (($facts['post_ok'] ?? false) !== true) {
            return self::no('文章「' . $slug . '」用 content.post 读不到（回收站 / 定时未到点 / 不存在）。'
                . '接口不校验资源可读性，此时写入会变成一条自己都看不见的孤儿绑定。');
        }
        $refs = TermsRefs::split((string) ($body['terms'] ?? ''));
        $check = TermsRefs::check($refs, $known);
        if (($check['ok'] ?? false) !== true) return self::no((string) $check['message']);
        $refs = (array) $check['refs'];
        if ($refs === [] && trim((string) ($body['confirm'] ?? '')) !== $slug) {
            return self::no('提交空集合表示**清空这篇文章的全部术语**，不是「什么都不做」。'
                . '请在确认框里原样手写 ' . $slug . ' 再提交。');
        }
        return self::yes('attach', 'content.attach', ['slug' => $slug, 'terms' => $refs],
            '把 ' . $slug . ' 的术语整体替换为 ' . ($refs === [] ? '空集合' : implode(' / ', $refs)) . '。',
            'content.attach 是整体替换而不是追加：提交的数组就是最终集合；'
                . '只要有一个引用不存在，整个操作失败，不会出现部分成功。');
    }

    /** @param array<string, mixed> $body @param array<string, array<string, mixed>> $known */
    private static function term(array $body, array $known): array|string
    {
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) return '术语 ID 必须是正整数（清单里的 id 列）。';
        $term = TermsRefs::byId($known, $id);
        return $term ?? ('清单里没有 ID ' . $id . ' 的术语；接口会返回 40404。'
            . '可能是刚被删掉，请刷新页面重取清单。');
    }

    /** @return array{ok: true, op: string, operation: string, in: array<string, mixed>, detail: string, hint: string} */
    private static function yes(string $op, string $operation, array $in, string $detail, string $hint): array
    {
        return ['ok' => true, 'op' => $op, 'operation' => $operation,
            'in' => $in, 'detail' => $detail, 'hint' => $hint];
    }

    /** @return array{ok: false, message: string} */
    private static function no(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }
}
