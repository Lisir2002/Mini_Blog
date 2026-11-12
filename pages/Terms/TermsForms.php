<?php
declare(strict_types=1);
namespace Wx\Pages\Terms;

/**
 * 两张写表单 —— 术语增删改、文章术语整体替换。
 *
 * 渲染条件比「有没有票据」更严：清单读不到（50101）时连术语表单都不给，
 * 因为此时无法核对引用是否存在，给一个能提交的表单等于让人去撞 40404；
 * 文章读不到时挂载表单也不给，避免把术语挂到一篇自己看不见的文章上。
 */
final class TermsForms
{
    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    public static function build(array $data): array
    {
        $ticket = (string) ($data['ticket'] ?? '');
        $action = self::action($data);
        $hidden = self::hidden($data, $ticket);
        $form = (array) ($data['form'] ?? []);
        return [self::terms($data, $action, $hidden, $ticket, $form),
            self::attach($data, $action, $hidden, $ticket, $form)];
    }

    /** @param array<string, mixed> $data */
    private static function action(array $data): string
    {
        $slug = (string) ($data['slug'] ?? '');
        return '/console/terms' . ($slug !== '' ? '?slug=' . rawurlencode($slug) : '');
    }

    /** @param array<string, mixed> $data @return list<array<string, string>> */
    private static function hidden(array $data, string $ticket): array
    {
        $out = [['name' => '_form_ticket', 'value' => $ticket]];
        if ((string) ($data['slug'] ?? '') !== '') {
            $out[] = ['name' => 'slug', 'value' => (string) $data['slug']];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<array<string, string>> $hidden
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    private static function terms(array $data, string $action, array $hidden, string $ticket, array $form): array
    {
        $state = (string) ($data['terms_state'] ?? 'idle');
        if (!in_array($state, ['ok', 'empty'], true)) {
            return self::wait('terms-form-unavailable', '术语清单不可用，写入口关闭',
                'content.terms 在当前部署下读不到（' . (string) ($data['terms_fail'] ?? '50101') . '）。'
                . '内存降级模式不是「还没有分类」；清单不确定时页面不提供任何写入口。');
        }
        if (($data['can_terms'] ?? false) !== true || $ticket === '') {
            return self::wait('terms-form-denied', '术语写入口不可用',
                '新建 / 改名 / 删除术语要求 content.manage_terms：能看分类不等于能改分类。上面的只读清单仍然有效。');
        }
        $fields = [];
        foreach (TermsFormSpec::termFields() as $f) {
            $f['type'] = 'text';
            $f['value'] = (string) ($form[$f['name']] ?? '');
            $fields[] = $f;
        }
        foreach (TermsFormSpec::termButtons() as $b) $fields[] = $b;
        return ['type' => 'form', 'id' => 'terms-manage-form', 'title' => '新建 / 改名 / 删除术语',
            'action' => $action, 'method' => 'post', 'hidden' => $hidden, 'fields' => $fields,
            'note' => TermsFormSpec::termNote()];
    }

    /**
     * @param array<string, mixed> $data
     * @param list<array<string, string>> $hidden
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    private static function attach(array $data, string $action, array $hidden, string $ticket, array $form): array
    {
        if ((string) ($data['slug'] ?? '') === '') {
            return self::wait('terms-attach-wait', '先选中一篇文章',
                '挂载是文章级的：上面按 content.posts 选一篇，这里才会出现可提交的表单。');
        }
        if ((string) ($data['post_state'] ?? '') !== 'found'
            || (string) ($data['attached_state'] ?? '') === 'unknown') {
            return self::wait('terms-attach-unreadable', '这篇文章读不到，挂载入口关闭',
                'content.post 返回 ' . (string) ($data['post_error'] ?? '无法读取')
                . '（回收站 / 定时未到点 / 无权）。读不到正文时页面不猜「没有术语」，也不写入孤儿绑定。');
        }
        if (($data['can_edit'] ?? false) !== true || $ticket === '') {
            return self::wait('terms-attach-denied', '文章挂载入口不可用',
                '改文章的术语要求 content.edit，与术语管理的 content.manage_terms 是两个权限点。');
        }
        $value = (string) ($form['terms'] ?? TermsRefs::prefill((array) ($data['attached'] ?? [])));
        $fields = [
            ['tag' => 'textarea', 'id' => 't-attach-terms', 'name' => 'terms',
                'placeholder' => '每行一个 category:slug / tag:slug', 'value' => $value],
            ['tag' => 'input', 'id' => 't-attach-confirm', 'name' => 'confirm', 'type' => 'text',
                'placeholder' => '清空全部术语时原样手写文章 slug',
                'value' => (string) ($form['confirm'] ?? '')],
            ['tag' => 'submit', 'name' => 'op', 'value' => 'attach', 'label' => '整体替换这篇文章的术语'],
        ];
        return ['type' => 'form', 'id' => 'terms-attach-form',
            'title' => '给 ' . (string) $data['slug'] . ' 设置分类与标签',
            'action' => $action, 'method' => 'post', 'hidden' => $hidden, 'fields' => $fields,
            'note' => TermsFormSpec::attachNote()];
    }

    /** @return array<string, mixed> */
    private static function wait(string $id, string $title, string $value): array
    {
        return ['type' => 'notice', 'id' => $id, 'tone' => 'info',
            'state' => 'permission-denied', 'title' => $title, 'value' => $value];
    }
}
