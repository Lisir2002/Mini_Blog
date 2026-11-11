<?php
declare(strict_types=1);
namespace Wx\Pages\Write;

/**
 * 编辑器的区块组装 —— 纯函数，无 IO。
 *
 * 两个提交按钮就是状态机：`保存草稿` 发 status=draft，`发布` 发 status=published。
 * 不用下拉框是因为零脚本环境下 select 的默认值更容易被误读成「已经发布」；
 * 按钮把「这次要变成什么」写在动作上，提交前后都不会有歧义。
 */
final class WriteBlocks
{
    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed>|null $error
     * @return list<array<string, mixed>>
     */
    public static function build(array $post, string $ticket, ?array $error,
        string $notice, int $status): array
    {
        $slug = (string) ($post['slug'] ?? '');
        $edit = $slug !== '';
        $blocks = [];

        if ($notice !== '') {
            $blocks[] = ['type' => 'notice', 'id' => 'write-notice', 'tone' => 'ok',
                'state' => 'done', 'title' => $edit ? '已保存' : '已创建', 'value' => $notice];
        }
        if ($error !== null) {
            $blocks[] = ['type' => 'notice', 'id' => 'write-error', 'tone' => 'warn',
                'state' => 'failed', 'title' => '没有保存',
                'value' => (string) ($error['message'] ?? '请检查填写内容后重试。')];
        }

        if ($status >= 400 && $post === []) {
            $blocks[] = ['type' => 'links', 'id' => 'write-back', 'title' => '返回', 'items' => [
                ['label' => '内容管理', 'href' => '/console/content'],
                ['label' => '新建一篇', 'href' => '/console/write'],
            ]];
            return $blocks;
        }

        if ($edit) {
            $blocks[] = ['type' => 'table', 'id' => 'write-meta', 'title' => '这篇文章', 'rows' => [
                ['k' => 'slug', 'v' => $slug, 'ok' => true],
                ['k' => '状态', 'v' => (string) ($post['status'] ?? 'draft')],
                ['k' => '作者', 'v' => (string) ($post['author'] ?? '(未署名)')],
                ['k' => '更新时间', 'v' => (string) ($post['updated_at'] ?? '(未知)')],
            ]];
        }

        if ($ticket === '') {
            $blocks[] = ['type' => 'notice', 'id' => 'write-ticket', 'tone' => 'warn',
                'state' => 'unavailable', 'title' => '暂时不能保存',
                'value' => '表单票据签发失败（storage/form-ticket.key 不可写）。'
                    . '为避免提交后必然 401，这里不显示编辑表单；请让运维检查 storage 权限。'];
            return $blocks;
        }

        $hidden = [['name' => '_form_ticket', 'value' => $ticket]];
        if ($edit) {
            $hidden[] = ['name' => 'slug', 'value' => $slug];
            $hidden[] = ['name' => 'mode', 'value' => 'edit'];
        }
        $fields = [
            ['tag' => 'input', 'id' => 'title', 'name' => 'title', 'type' => 'text',
             'value' => (string) ($post['title'] ?? ''), 'placeholder' => '标题'],
        ];
        if (!$edit) {
            $fields[] = ['tag' => 'input', 'id' => 'slug', 'name' => 'slug', 'type' => 'text',
                'placeholder' => '地址 slug（可留空，系统按时间生成）'];
        }
        $fields[] = ['tag' => 'textarea', 'id' => 'body', 'name' => 'body',
            'value' => (string) ($post['body'] ?? ''), 'placeholder' => '正文'];
        $fields[] = ['tag' => 'submit', 'name' => 'status', 'value' => 'draft', 'label' => '保存草稿'];
        $fields[] = ['tag' => 'submit', 'name' => 'status', 'value' => 'published', 'label' => '发布'];

        $blocks[] = ['type' => 'form', 'id' => 'write-form',
            'title' => $edit ? '编辑文章' : '新建文章',
            'action' => '/console/write', 'method' => 'post',
            'hidden' => $hidden, 'fields' => $fields,
            'note' => '保存成功会回到本页（303）；失败不会跳转，正文会原样留在表单里。'];
        $nav = [
            ['label' => '内容管理', 'href' => '/console/content'],
            ['label' => '审核队列', 'href' => '/console/queue'],
        ];
        if ($edit) $nav[] = ['label' => '查看文章', 'href' => '/post?slug=' . rawurlencode($slug)];
        $blocks[] = ['type' => 'links', 'id' => 'write-nav', 'title' => '去别处', 'items' => $nav];
        return $blocks;
    }
}
