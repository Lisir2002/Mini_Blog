<?php
declare(strict_types=1);
namespace Wx\Pages\Attachments;

/**
 * 附件库的区块编排 —— 纯数据，不产 HTML。
 *
 * 三段式：选文章 → 看这篇的附件 → 选一条来改。
 * 顺序不能倒过来：attachments.list 的 post_slug 是必填的，系统没有「列出全站附件」
 * 的接口，所以「选中某篇文章」不是交互偏好，而是这条能力唯一的入口。
 */
final class AttachmentsBlocks
{
    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    public static function build(array $data): array
    {
        if (($data['ok'] ?? false) !== true) {
            return [[
                'type' => 'notice', 'id' => 'attach-error', 'tone' => 'warn',
                'state' => (int) ($data['status'] ?? 503) === 422 ? 'bad-param' : 'unavailable',
                'title' => (int) ($data['status'] ?? 503) === 422 ? '查询条件不合法' : '附件数据不可用',
                'value' => (string) ($data['error'] ?? '未知原因'),
            ], self::boundary()];
        }
        $blocks = array_merge([self::header()], AttachmentsNotice::build($data));
        if ((string) ($data['slug'] ?? '') === '') {
            return array_merge($blocks, self::posts($data), [self::boundary()]);
        }
        return array_merge($blocks, self::article($data), self::attachments($data),
            AttachmentsForms::build($data), [self::nav($data), self::boundary()]);
    }

    /** @return array<string, mixed> */
    private static function header(): array
    {
        return ['type' => 'notice', 'id' => 'attach-scope', 'tone' => 'info', 'state' => 'scope',
            'title' => '附件库：先选文章，再选附件',
            'value' => '附件元数据来自 attachments.list（必填 post_slug），正文引用来自 content.post，'
                . '改动走 attachments.update / attachments.delete。'
                . '下载走 /attach/download?id= 二进制通道，每次都二次鉴权 —— 本页只给链接，'
                . '不把文件字节读进页面层。'];
    }

    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    private static function posts(array $data): array
    {
        if ($data['posts'] === null) {
            return [['type' => 'notice', 'id' => 'attach-posts-broken', 'tone' => 'warn',
                'state' => 'unavailable', 'title' => '文章清单读不到',
                'value' => (string) ($data['posts_fail'] ?? '接口没有返回数据，也没有给出原因。')
                    . ' 没有文章清单就选不中文章，附件库在此停住 —— 不假装「站点没有附件」。']];
        }
        if ((array) $data['posts'] === []) {
            return [['type' => 'notice', 'id' => 'attach-posts-empty', 'tone' => 'warn',
                'state' => 'empty', 'title' => '站点还没有文章',
                'value' => '附件必须挂在文章上（post_slug 必填），没有文章就没有可浏览的附件。'
                    . '这与「有文章但都没附件」是两件事。']];
        }
        $items = [];
        foreach ((array) $data['posts'] as $row) {
            $slug = (string) ($row['slug'] ?? '');
            if ($slug === '') continue;
            $items[] = ['label' => (string) ($row['title'] ?? $slug) . '（' . $slug . '）',
                'href' => '/console/attachments?slug=' . rawurlencode($slug),
                'note' => '状态 ' . (string) ($row['status'] ?? '?')];
        }
        return [['type' => 'links', 'id' => 'attach-posts', 'title' => '选择一篇文章 · 最多 20 篇',
            'items' => $items]];
    }

    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    private static function article(array $data): array
    {
        $state = (string) ($data['post_state'] ?? 'idle');
        if ($state === 'found') {
            $post = (array) ($data['post'] ?? []);
            return [['type' => 'notice', 'id' => 'attach-current', 'tone' => 'info', 'state' => 'selected',
                'title' => '当前文章 · ' . (string) ($post['title'] ?? $data['slug']),
                'value' => 'slug=' . (string) $data['slug'] . ' · 状态 '
                    . (string) ($post['status'] ?? '?') . '。'
                    . '下面的附件清单按当前身份取数，因此私有附件也会出现在这里；'
                    . '文章页刻意不带身份，那里只显示公开附件。']];
        }
        if ($state === 'missing') {
            return [['type' => 'notice', 'id' => 'attach-article-missing', 'tone' => 'warn',
                'state' => 'missing', 'title' => '这篇文章读不到',
                'value' => (string) ($data['post_error'] ?? '') . ' 可能在回收站里，或是尚未到点的定时文章。'
                    . '读不到正文就无法确认附件是否还被引用，因此本页不渲染任何写入口。']];
        }
        return [['type' => 'notice', 'id' => 'attach-article-broken', 'tone' => 'warn',
            'state' => 'unavailable', 'title' => '文章正文读不到',
            'value' => (string) ($data['post_error'] ?? '未知原因')
                . ' 删除 / 改名会因此被拒绝（fail-closed）：「查不到引用」不等于「没有引用」。']];
    }

    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    private static function attachments(array $data): array
    {
        if ((string) ($data['post_state'] ?? '') !== 'found') return [];
        $state = (string) ($data['attachments_state'] ?? 'idle');
        if ($state === 'unavailable') {
            return [['type' => 'notice', 'id' => 'attach-list-broken', 'tone' => 'warn',
                'state' => 'unavailable', 'title' => '附件清单读不到',
                'value' => (string) ($data['attachments_error'] ?? '未知原因')]];
        }
        if ($state === 'empty') {
            return [['type' => 'notice', 'id' => 'attach-list-empty', 'tone' => 'warn',
                'state' => 'empty', 'title' => '这篇文章没有可见附件',
                'value' => 'total=0 是「当前身份可见的附件数」，不是全站附件总数。'
                    . '也可能附件被挂到了别的文章、或已经软删除 —— 系统没有跨文章清点附件的接口。']];
        }

        $rows = $downloads = $picks = [];
        foreach ((array) $data['items'] as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) continue;
            $name = (string) ($row['name'] ?? ('附件 #' . $id));
            $vis = (string) ($row['visibility'] ?? 'public');
            $ref = (array) (($data['refs'][$id] ?? []));
            $rows[] = ['k' => '#' . $id . ' ' . $name,
                'v' => (string) ($row['mime'] ?? '?') . ' · ' . (int) ($row['bytes'] ?? 0) . ' B · ' . $vis,
                'ok' => $vis === 'public',
                'note' => '上传者 #' . (int) ($row['uploader_id'] ?? 0)
                    . ' · sha256 ' . substr((string) ($row['sha256'] ?? ''), 0, 12)
                    . ' · ' . (string) ($ref['label'] ?? '')];
            $downloads[] = ['label' => '下载 #' . $id . ' ' . $name,
                'href' => (string) ($row['url'] ?? ('/attach/download?id=' . $id)),
                'note' => $vis === 'private' ? '私有：需登录，无 attachment.read 会 403' : '公开'];
            if (str_starts_with((string) ($row['mime'] ?? ''), 'image/')) {
                $downloads[] = ['label' => '↳ 内联预览 #' . $id,
                    'href' => '/attach/download?id=' . $id . '&disposition=inline',
                    'note' => '仅图片允许 inline，仍带 CSP sandbox'];
            }
            $picks[] = ['label' => '管理 #' . $id . ' ' . $name,
                'active' => (int) ($data['id'] ?? 0) === $id,
                'href' => '/console/attachments?slug=' . rawurlencode((string) $data['slug'])
                    . '&id=' . $id];
        }
        return [
            ['type' => 'table', 'id' => 'attach-list',
                'title' => '附件 · 当前身份可见 ' . (int) ($data['total'] ?? 0) . ' 条', 'rows' => $rows],
            ['type' => 'links', 'id' => 'attach-downloads', 'title' => '受控下载', 'items' => $downloads],
            ['type' => 'links', 'id' => 'attach-picks', 'title' => '选择要改的附件', 'items' => $picks],
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function nav(array $data): array
    {
        return ['type' => 'links', 'id' => 'attach-nav', 'title' => '去别处', 'items' => [
            ['label' => '换一篇文章', 'href' => '/console/attachments', 'note' => '回到文章清单'],
            ['label' => '内容管理', 'href' => '/console/content', 'note' => '文章在这里'],
            ['label' => '写文章', 'href' => '/console/write', 'note' => '正文里的引用在这里改'],
            ['label' => '文章页', 'href' => '/post?slug=' . rawurlencode((string) $data['slug']),
             'note' => '读者视角只看得到公开附件'],
        ]];
    }

    /** @return array<string, mixed> */
    private static function boundary(): array
    {
        return ['type' => 'notice', 'id' => 'attach-boundary', 'tone' => 'info', 'state' => 'boundary',
            'title' => '这一页的边界',
            'value' => '没有「列出全站附件」的接口，只能按文章浏览；total 是当前身份可见数，'
                . '不是库内总数。正文引用检查是字面匹配（URL / 展示名），不是结构化引用表 —— '
                . '所以它只用来拦住明显会坏的操作，读不到正文时一律拒绝删除。'
                . '删除是软删除，字节要等引用计数为 0 才由独立流程回收；'
                . '下载链接每次都二次鉴权，本页不代理字节。'];
    }
}
