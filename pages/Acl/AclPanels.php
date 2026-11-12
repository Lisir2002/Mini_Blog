<?php
declare(strict_types=1);
namespace Wx\Pages\Acl;

/**
 * 资源 ACL 面板的只读区块（用户组面板见 AclGroupPanels）。
 *
 * 「这篇文章的 ACL」是页面层从 permission.acl.list 里筛出来的：接口没有
 * 按资源过滤的参数，只返回最近 100 条。所以空态有三种完全不同的含义：
 * 这篇文章确实没有条目 / 条目在更早的记录里被 100 条上限截掉了 / 接口读不到。
 */
final class AclPanels
{
    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    public static function acl(array $data): array
    {
        if ((string) ($data['slug'] ?? '') === '') return self::posts($data);
        $blocks = [self::article($data)];
        if ($data['entries'] === null) {
            $blocks[] = self::warn('acl-entries-broken', 'ACL 清单读不到',
                (string) ($data['entries_fail'] ?? '接口没有返回数据，也没有给出原因。')
                . ' 不能把「读不到」显示成「这篇文章没有规则」。', 'unavailable');
            return $blocks;
        }
        $rows = [];
        foreach ((array) ($data['filtered'] ?? []) as $row) {
            $allow = (int) ($row['allow'] ?? 0) === 1;
            $rows[] = ['k' => (string) ($row['subject_type'] ?? '?') . ':'
                    . (string) ($row['subject_id'] ?? ''),
                'v' => ($allow ? '放行' : '显式拒绝') . ' ' . (string) ($row['capability'] ?? '?'),
                'ok' => $allow,
                'note' => (string) ($row['field'] ?? '') === ''
                    ? '整资源级 · ' . (string) ($row['resource_id'] ?? '')
                    : '字段 ' . (string) $row['field'] . ' · ' . (string) ($row['resource_id'] ?? '')];
        }
        if ($rows === []) {
            $blocks[] = self::warn('acl-empty', '没有筛出这篇文章的 ACL 条目',
                '接口返回的最近 ' . (int) ($data['entries_total'] ?? 0)
                . ' 条记录里没有 type=post、id=' . (string) $data['slug'] . ' 的条目。'
                . '这不等于「没有规则」：permission.acl.list 有 100 条上限，更早的条目看不到。', 'empty');
        } else {
            $blocks[] = ['type' => 'table', 'id' => 'acl-entries',
                'title' => '这篇文章的 ACL · 筛出 ' . count($rows) . ' 条', 'rows' => $rows];
            $blocks[] = self::warn('acl-deny-note', '显式 deny 优先级最高',
                'deny 压过角色通配符与继承链，多条按插入顺序判定且 deny 先扫描。'
                . '整资源级条目对任意请求生效；带 field 的条目只对同名字段生效，'
                . '所以「禁止读 body」不会把整篇文章从列表里剔除。', 'boundary');
        }
        if ((int) ($data['entries_total'] ?? 0) >= 100) {
            $blocks[] = self::warn('acl-truncated', '清单已达 100 条上限',
                'permission.acl.list 只返回最近 100 条，更早的规则在页面上看不到，'
                . '但它们仍然生效。别把这一页当成完整快照。', 'truncated');
        }
        return $blocks;
    }

    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    private static function posts(array $data): array
    {
        if ($data['posts'] === null) {
            return [self::warn('acl-posts-broken', '文章清单读不到',
                (string) ($data['posts_fail'] ?? '未知原因')
                . ' 没有文章清单就选不中资源，ACL 面板在此停住。', 'unavailable')];
        }
        if ((array) $data['posts'] === []) {
            return [self::warn('acl-posts-empty', '站点还没有文章',
                '资源 ACL 挂在具体资源上（type+id）。没有文章就没有可设规则的对象。', 'empty')];
        }
        $items = [];
        foreach ((array) $data['posts'] as $row) {
            $slug = (string) ($row['slug'] ?? '');
            if ($slug === '') continue;
            $items[] = ['label' => (string) ($row['title'] ?? $slug) . '（' . $slug . '）',
                'href' => '/console/acl?panel=acl&slug=' . rawurlencode($slug),
                'note' => '状态 ' . (string) ($row['status'] ?? '?')];
        }
        return [['type' => 'links', 'id' => 'acl-posts', 'title' => '先选中一篇文章 · 最多 20 篇',
            'items' => $items]];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function article(array $data): array
    {
        $state = (string) ($data['post_state'] ?? 'idle');
        if ($state === 'found') {
            $post = (array) ($data['post'] ?? []);
            return ['type' => 'notice', 'id' => 'acl-current', 'tone' => 'info', 'state' => 'selected',
                'title' => '当前资源 · post:' . (string) $data['slug'],
                'value' => '文章「' . (string) ($post['title'] ?? $data['slug']) . '」，状态 '
                    . (string) ($post['status'] ?? '?') . '。'
                    . 'permission.acl.set 不校验 id 是否存在，页面先用 content.post 复核过这篇文章。'];
        }
        return self::warn('acl-article-broken', '这篇文章读不到',
            (string) ($data['post_error'] ?? '未知原因')
            . ' 可能在回收站或尚未到点的定时文章里。不渲染写入口：'
            . '给不存在的资源设 ACL 只会写出一条永远不命中的孤儿规则。',
            $state === 'missing' ? 'missing' : 'unavailable');
    }

    /** @return array<string, mixed> */
    private static function warn(string $id, string $title, string $value, string $state): array
    {
        return ['type' => 'notice', 'id' => $id,
            'tone' => in_array($state, ['unavailable', 'missing', 'inconsistent', 'truncated'], true)
                ? 'warn' : 'info',
            'state' => $state, 'title' => $title, 'value' => $value];
    }
}
