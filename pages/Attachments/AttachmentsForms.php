<?php
declare(strict_types=1);
namespace Wx\Pages\Attachments;

/**
 * 单条附件的操作台 —— 先选中 ?id=，再渲染一张表。
 *
 * 五个动作共用一张表而不是五个：它们作用于同一条记录，字段只有三个
 * （新名 / 目标 slug / 手写确认），把「现在在改哪一条」摆在标题里比切面板更清楚。
 * 可见性用两个枚举按钮给出，绝不让自由输入 —— 接口把任何非 private 的
 * 字符串都当成 public，写错一个字母会静默把私有附件公开。
 */
final class AttachmentsForms
{
    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    public static function build(array $data): array
    {
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) return [];
        $row = null;
        foreach ((array) ($data['items'] ?? []) as $item) {
            if (is_array($item) && (int) ($item['id'] ?? 0) === $id) { $row = $item; break; }
        }
        if ($row === null) {
            return [['type' => 'notice', 'id' => 'attach-pick-missing', 'tone' => 'warn',
                'state' => 'not-found', 'title' => '选中的附件不在这篇文章里',
                'value' => '#' . $id . ' 已被删除、改挂到别的文章，或当前身份看不到它。'
                    . '下面的清单是按当前可见性重新取的，请重新选择。']];
        }
        if (($data['can'] ?? false) !== true || (string) ($data['ticket'] ?? '') === '') {
            return [['type' => 'notice', 'id' => 'attach-no-write', 'tone' => 'info',
                'state' => 'permission-denied', 'title' => '写入口不可用',
                'value' => '改动附件要求 attachment.manage（或有效的表单票据）。'
                    . '上面的清单与下载链接仍然可用 —— 能看不等于能改。']];
        }

        $hidden = [
            ['name' => '_form_ticket', 'value' => (string) $data['ticket']],
            ['name' => 'id', 'value' => (string) $id],
            ['name' => 'slug', 'value' => (string) ($data['slug'] ?? '')],
        ];
        $post = (array) ($data['form'] ?? []);
        $fields = [
            ['tag' => 'input', 'id' => 'att-new-name', 'name' => 'new_name', 'type' => 'text',
                'value' => (string) ($post['new_name'] ?? ''), 'placeholder' => '新的展示名（改名时填，最多 120 字符）'],
            ['tag' => 'input', 'id' => 'att-target', 'name' => 'target_slug', 'type' => 'text',
                'value' => (string) ($post['target_slug'] ?? ''), 'placeholder' => '目标文章 slug（改挂载时填）'],
            ['tag' => 'input', 'id' => 'att-confirm', 'name' => 'confirm', 'type' => 'text',
                'value' => (string) ($post['confirm'] ?? ''),
                'placeholder' => '手写确认：删除填 ' . $id . '；正文按名字引用时改名填新名'],
        ];
        foreach (self::buttons() as $button) $fields[] = $button;

        // action 必须带回 slug / id：页面靠 GET 查询参数选文章与附件，
        // POST 走同一个 URL 才能重新取到同一份清单（失败时不跳转、不丢上下文）。
        // 票据只绑路径，不绑查询串，所以这里不会破坏验签。
        $action = '/console/attachments?' . http_build_query([
            'slug' => (string) ($data['slug'] ?? ''), 'id' => $id,
        ]);
        $ref = (array) (($data['refs'][$id] ?? []));
        return [
            ['type' => 'notice', 'id' => 'attach-pick-scope', 'tone' => 'info', 'state' => 'guide',
             'title' => '正在管理 #' . $id . ' · ' . (string) ($row['name'] ?? ''),
             'value' => (string) ($ref['label'] ?? '正文引用未知')
                . '。下载走 /attach/download?id=' . $id . '（二进制通道，每次二次鉴权）；'
                . '本页不把文件字节读进页面层。'],
            ['type' => 'form', 'id' => 'attach-form', 'title' => '改动这条附件',
             'action' => $action, 'method' => 'post', 'hidden' => $hidden,
             'fields' => $fields, 'note' => '成功 303 回到本页；失败不跳转并回填。'
                . '删除前会查正文引用：还被引用的附件会被拒绝删除。'
                . '改挂载前会确认目标文章存在 —— 系统没有列出全站附件的接口。'],
        ];
    }

    /** @return list<array<string, string>> */
    private static function buttons(): array
    {
        return [
            ['tag' => 'submit', 'name' => 'op', 'value' => 'rename', 'label' => '改名'],
            ['tag' => 'submit', 'name' => 'op', 'value' => 'public', 'label' => '设为公开'],
            ['tag' => 'submit', 'name' => 'op', 'value' => 'private', 'label' => '设为私有'],
            ['tag' => 'submit', 'name' => 'op', 'value' => 'move', 'label' => '改挂载文章'],
            ['tag' => 'submit', 'name' => 'op', 'value' => 'delete', 'label' => '删除（需确认）'],
        ];
    }
}
