<?php
declare(strict_types=1);
namespace Wx\Pages\Terms;

/**
 * 分类与标签页的区块编排 —— 上面看现状，下面做改动。
 *
 * 顺序刻意固定：清单 → 文章窗口 → 当前文章 → 两张写表单 → 边界。
 * 先看清楚「有哪些术语」「这篇文章已挂了什么」，才谈得上整体替换；
 * 反过来放会让人以为提交的是增量。
 */
final class TermsBlocks
{
    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    public static function build(array $data): array
    {
        if (($data['ok'] ?? false) !== true) {
            return [[
                'type' => 'notice', 'id' => 'terms-error', 'tone' => 'warn',
                'state' => (int) ($data['status'] ?? 503) === 422 ? 'bad-param' : 'unavailable',
                'title' => (int) ($data['status'] ?? 503) === 422 ? '查询条件不合法' : '术语数据不可用',
                'value' => (string) ($data['error'] ?? '未知原因'),
            ], self::boundary()];
        }
        return array_merge([self::header()], TermsNotice::build($data),
            TermsPanels::build($data), TermsForms::build($data),
            [self::nav(), self::boundary()]);
    }

    /** @return array<string, mixed> */
    private static function header(): array
    {
        return ['type' => 'notice', 'id' => 'terms-scope', 'tone' => 'info', 'state' => 'scope',
            'title' => '分类 · 标签 · 文章挂载',
            'value' => 'content.terms 的读路径原本只在前台归档页用过，后台没有管理入口；'
                . '这一版把 content.terms.manage 与 content.attach 两个写接口接成一条闭环。'
                . '页面声明 content.read；术语增删改现场问 content.manage_terms，'
                . '文章挂载现场问 content.edit。提交的是完整集合，不是增量。'];
    }

    /** @return array<string, mixed> */
    private static function nav(): array
    {
        return ['type' => 'links', 'id' => 'terms-nav', 'title' => '去别处', 'items' => [
            ['label' => '内容管理', 'href' => '/console/content', 'note' => '文章状态与回收站在这里'],
            ['label' => '正文创作', 'href' => '/console/write', 'note' => '写文章与改正文'],
            ['label' => '归档浏览', 'href' => '/archive', 'note' => '读者看到的分类与标签'],
            ['label' => '资源 ACL', 'href' => '/console/acl', 'note' => '字段级授权在那一页'],
        ]];
    }

    /** @return array<string, mixed> */
    private static function boundary(): array
    {
        return ['type' => 'notice', 'id' => 'terms-boundary', 'tone' => 'info', 'state' => 'boundary',
            'title' => '这一页的边界',
            'value' => 'kind 与 slug 不可修改，改 slug 会让旧链接 404；同 kind 下重复返回 40911 而不是复用。'
                . 'content.attach 是整体替换，一次请求里有一个引用不存在就整体失败。'
                . '术语清单最多显示 60 条、文章窗口最多 20 篇，都不是完整快照。'
                . 'post_count 不含回收站文章。内存降级模式下接口返回 50101，写入口关闭。'
                . '不做批量导入、不做术语合并、不改 core/ 或系统层。'];
    }
}
