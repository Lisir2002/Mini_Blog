<?php
declare(strict_types=1);
namespace Wx\Pages\ContentAdmin;

/**
 * 「排期与推荐位」面板的区块组装 —— 从 ContentAdminBlocks 拆出的独立协作者。
 *
 * 选中一篇文章后要渲染「先选文章」链接组 + 两张独立表单 + 四类不可用说明，
 * 全塞进主区块文件会撑破单文件职责预算。本类只产结构化数据，无 IO。
 * 落点为什么是内容管理页（而不是写作页），见 ContentAdminBlocks::build 的注释。
 */
final class ContentAdminFocusBlocks
{
    /**
     * @param array<string, mixed> $data @param array<string, bool> $cap
     * @return list<array<string, mixed>>
     */
    public static function build(string $focus, array $data, string $ticket, array $cap): array
    {
        $out = [];
        $picks = [];
        foreach ((array) ($data['items'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $slug = (string) ($item['slug'] ?? '');
            if ($slug === '') continue;
            $at = (string) ($item['publish_at'] ?? '');
            $picks[] = [
                'label'  => (string) ($item['title'] ?? $slug),
                'href'   => '/console/content?focus=' . rawurlencode($slug),
                'active' => $slug === $focus,
                'note'   => trim(($at !== '' ? '定时 ' . $at : '')
                    . (!empty($item['pinned']) ? ' 置顶' : '')),
            ];
        }
        if ($picks !== []) {
            $out[] = ['type' => 'links', 'id' => 'content-focus',
                'title' => '设置排期与推荐位：先选一篇文章', 'items' => $picks];
        }
        if ($focus !== '') {
            foreach (self::panel($focus, $data, $ticket, $cap) as $block) $out[] = $block;
        }
        return $out;
    }

    /**
     * 选中一篇文章后的设置面板 —— 两张独立表单，字段绝不混装。
     * 找不到就明说「没加载」，不许渲染一个指向别处的表单（与空态不伪装同一纪律）。
     * @param array<string, mixed> $data @param array<string, bool> $cap
     * @return list<array<string, mixed>>
     */
    private static function panel(string $focus, array $data,
        string $ticket, array $cap): array
    {
        $item = null;
        foreach ((array) ($data['items'] ?? []) as $row) {
            if (is_array($row) && (string) ($row['slug'] ?? '') === $focus) { $item = $row; break; }
        }
        if ($item === null) {
            return [['type' => 'notice', 'id' => 'content-focus-miss', 'tone' => 'warn',
                'state' => 'unavailable', 'title' => '没有加载设置面板',
                'value' => '文章「' . $focus . '」不在本页列出的前 20 篇里。'
                    . '请用上方链接或搜索定位后再选；这里不显示看似能设置、实则指向别处的表单。']];
        }

        $slug      = (string) ($item['slug'] ?? '');
        $publishAt = (string) ($item['publish_at'] ?? '');
        $pinned    = !empty($item['pinned']);
        $weight    = (int) ($item['sort_weight'] ?? 0);
        $out = [['type' => 'notice', 'id' => 'content-focus-state', 'tone' => 'info',
            'state' => 'ok', 'title' => '排期与推荐位 · ' . $slug,
            'value' => ($publishAt !== '' ? '已排期 ' . $publishAt : '未排期（立即发布）')
                . '；' . ($pinned ? '已置顶，权重 ' . $weight : '未置顶')
                . '。两张表单各自独立，只有对应按钮会读各自的格子。']];

        if ($ticket === '') {
            $out[] = ['type' => 'notice', 'id' => 'content-focus-ticket', 'tone' => 'warn',
                'state' => 'unavailable', 'title' => '写入口当前不可用',
                'value' => '表单票据签发失败（storage 不可写），不渲染必然 401 的按钮；请检查目录权限。'];
            return $out;
        }

        $hidden = [
            ['name' => '_form_ticket', 'value' => $ticket],
            ['name' => 'slug', 'value' => $slug],
        ];
        if ($cap['can_schedule'] ?? false) {
            $value = '';
            if ($publishAt !== '' && ($ts = strtotime($publishAt)) !== false) {
                $value = date('Y-m-d\TH:i', $ts);
            }
            $out[] = ['type' => 'form', 'id' => 'content-schedule', 'title' => '发布排期',
                'body' => '文章：' . $slug, 'action' => '/console/content', 'method' => 'post',
                'hidden' => $hidden,
                'fields' => [
                    ['tag' => 'input', 'id' => 'publish-at', 'name' => 'publish_at',
                     'type' => 'datetime-local', 'value' => $value, 'placeholder' => '留空 = 取消排期'],
                    ['tag' => 'submit', 'name' => 'op', 'value' => 'schedule',
                     'label' => '设定排期 / 取消排期'],
                ],
                'note' => '发布排期：填未来时间会把文章切为「已发布」，但前台、搜索、站点地图都看不到，'
                    . '到点自动可见（读路径判定，不需要定时任务）；留空 = 取消排期并立即发布。'
                    . '只有本表单的按钮会读这个时间，时间解析不了会明确报错、不会静默立即发布。'];
        } else {
            $out[] = self::noEntry('content-schedule-missing', '缺少 content.publish 权限点');
        }

        if ($cap['can_pin'] ?? false) {
            $out[] = ['type' => 'form', 'id' => 'content-pin', 'title' => '推荐位',
                'body' => '文章：' . $slug, 'action' => '/console/content', 'method' => 'post',
                'hidden' => $hidden,
                'fields' => [
                    ['tag' => 'input', 'id' => 'pin-weight', 'name' => 'weight', 'type' => 'number',
                     'value' => (string) $weight, 'placeholder' => '权重 -1000~1000'],
                    ['tag' => 'submit', 'name' => 'op', 'value' => 'pin:on', 'label' => '置顶并保存权重'],
                    ['tag' => 'submit', 'name' => 'op', 'value' => 'pin:off', 'label' => '取消置顶'],
                ],
                'note' => '推荐位：置顶文章单独用 pinned=1 取，不混进时间线；权重倒序，范围 -1000~1000，'
                    . '超范围显式拒绝不静默截断。置顶状态由两个按钮枚举，不依赖复选框是否勾选。'];
        } else {
            $out[] = self::noEntry('content-pin-missing', '缺少 content.edit 权限点');
        }
        return $out;
    }

    private static function noEntry(string $id, string $reason): array
    {
        return ['type' => 'notice', 'id' => $id, 'tone' => 'warn', 'state' => 'unavailable',
            'title' => '这一项没有写入口',
            'value' => $reason . '。按钮与表单都不渲染 —— 点了必然被拒的入口比没有更糟；'
                . '权限变更请到角色授权页处理。'];
    }
}
