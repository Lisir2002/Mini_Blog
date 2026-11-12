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
    /**
     * 上传表单 —— 独立于改动表单：一张表单一种 enctype，混在一起会让
     * 「没选文件的改动提交」也被浏览器按 multipart 打包。
     *
     * 展示名不做输入：接口的 name 参数同时决定扩展名与展示名，另起名会破坏
     * 「扩展名 → 白名单 → 魔数」的类型判定。可见性用两个提交按钮给出枚举，
     * 绝不让自由输入 —— 接口把任何非 private 的字符串都当成 public。
     *
     * @param array<string, mixed> $data @return list<array<string, mixed>>
     */
    public static function uploadForm(array $data): array
    {
        if (($data['can_upload'] ?? false) !== true || (string) ($data['ticket'] ?? '') === '') {
            return [['type' => 'notice', 'id' => 'attach-upload-no', 'tone' => 'info',
                'state' => 'permission-denied', 'title' => '上传入口不可用',
                'value' => '上传要求 attachment.upload 权限点（与改动的 attachment.manage 互相独立）。'
                    . '清单与下载链接仍然可用。']];
        }
        $slug = (string) ($data['slug'] ?? '');
        return [['type' => 'form', 'id' => 'attach-upload-form',
            'title' => '上传新附件' . ($slug !== '' ? '（挂载到「' . $slug . '」）' : ''),
            'action' => '/console/attachments?' . http_build_query(['slug' => $slug]),
            'method' => 'post', 'enctype' => 'multipart/form-data',
            'hidden' => [
                ['name' => '_form_ticket', 'value' => (string) $data['ticket']],
                ['name' => 'slug', 'value' => $slug],
            ],
            'fields' => [
                ['tag' => 'input', 'id' => 'att-file', 'name' => 'file', 'type' => 'file',
                    'placeholder' => '选择文件（扩展名决定类型判定）'],
                ['tag' => 'submit', 'name' => 'op', 'value' => 'upload:public', 'label' => '以公开上传'],
                ['tag' => 'submit', 'name' => 'op', 'value' => 'upload:private', 'label' => '以私有上传'],
            ],
            'note' => '类型由扩展名白名单（png jpg jpeg gif webp pdf zip txt md csv mp3 mp4）'
                . '加文件头共同推导，SVG / HTML / JS / PHP 一律拒绝；单个文件上限 8 MB；'
                . '单篇文章最多 20 个附件。同名不同内容不会覆盖：存储路径由内容哈希决定。'
                . '失败不跳转，但文件内容不会保留 —— 需要重新选择文件。']];
    }

    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    public static function build(array $data): array
    {        $id = (int) ($data['id'] ?? 0);
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
