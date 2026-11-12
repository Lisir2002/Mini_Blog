<?php
declare(strict_types=1);
namespace Wx\Pages\Emojis;

/**
 * 表情目录区块编排 —— 纯函数，无 IO。
 *
 * 呈现纪律：内置与自定义分开标注；目录读失败 / 为空 / 被截断是三种状态；
 * 写入口缺权限、票据签不出、目录读不到也分别说清，不塌成「没有表情」。
 */
final class EmojisBlocks
{
    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    public static function build(array $data): array
    {
        $blocks = EmojisNotice::build($data);
        $blocks[] = ['type' => 'links', 'id' => 'emojis-nav', 'title' => '管理入口', 'items' => [
            ['label' => '控制台', 'href' => '/console'],
            ['label' => '附件库', 'href' => '/console/attachments'],
        ]];
        $blocks[] = self::kindLinks($data);
        $blocks[] = self::state($data);
        $blocks[] = self::catalog($data);
        foreach (EmojisForms::build($data) as $b) $blocks[] = $b;
        $blocks[] = self::boundary($data);
        return $blocks;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function kindLinks(array $data): array
    {
        $kind = (string) ($data['kind'] ?? 'any');
        $items = [];
        foreach (['any' => '全部', 'unicode' => 'Unicode', 'kaomoji' => '颜文字', 'image' => '图片'] as $k => $label) {
            $items[] = ['label' => $k === $kind ? '▸ ' . $label . '（当前）' : $label,
                'href' => '/console/emojis?kind=' . $k];
        }
        return ['type' => 'links', 'id' => 'emojis-kinds', 'title' => '按类型筛选', 'items' => $items];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function state(array $data): array
    {
        $state = (string) ($data['state'] ?? 'idle');
        if ($state === 'failed') {
            return self::block('emojis-state', 'warn', 'unavailable', '目录读取失败',
                (string) ($data['fail'] ?? 'emojis.list 不可用；这不是「还没有表情」。'));
        }
        if ($state === 'empty') {
            return self::block('emojis-state', 'info', 'empty', '目录里没有表情',
                'emojis.list 返回空清单 —— 这是真的空，不是读取失败；内置表通常不会为空，'
                . '若出现请检查表情系统是否装配。');
        }
        $value = '共 ' . (int) ($data['builtin_count'] ?? 0) . ' 条内置（不可删）+ '
            . (int) ($data['custom_count'] ?? 0) . ' 条自定义；本视图列出 '
            . (int) ($data['shown'] ?? 0) . ' 条。目录没有分页参数。';
        if (!empty($data['truncated'])) $value .= ' 已按 120 条上限截断，不是完整快照。';
        return self::block('emojis-state', 'info', 'ok', '表情目录', $value);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function catalog(array $data): array
    {
        $items = [];
        foreach ((array) ($data['items'] ?? []) as $e) {
            if (!is_array($e)) continue;
            $code = (string) ($e['code'] ?? '');
            $custom = (bool) ($e['custom'] ?? false);
            $line = ':' . $code . ': · ' . (string) ($e['value'] ?? '')
                . ' · ' . ($custom ? '自定义（可删）' : '内置（不可删）')
                . ' · ' . (string) ($e['kind'] ?? '');
            if ((int) ($e['attachment_id'] ?? 0) > 0) {
                $line .= ' · 附件 #' . (int) $e['attachment_id'];
            }
            $items[] = $line;
        }
        if ($items === []) {
            $items[] = '当前类型下没有条目。切换上面的类型筛选，或登记一条自定义表情。';
        }
        return ['type' => 'list', 'id' => 'emojis-catalog', 'items' => $items];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function boundary(array $data): array
    {
        $can = (bool) ($data['can_manage'] ?? false);
        return self::block('emojis-boundary', 'info', 'boundary', '边界与权限',
            '自定义表情只接受本站图片附件 id，不接受外链；短码被内置或已登记占用时回报占用原因，'
            . '不复用 40901。删除不重写历史 —— 正文存原文、渲染时解析，读者看到 :code: 字面量。'
            . '同一个 40403 在两个动作里含义不同：登记 = 附件不存在，删除 = 表情不存在，页面分别翻译。'
            . '页面声明 content.read（读目录），写动作现场问 '
            . ($can ? 'emoji.manage（当前有此权限）' : 'emoji.manage（当前缺失，写入口已关闭）')
            . '；不做批量导入、不做短码改名、不接管正文里的历史引用。');
    }

    /** @return array<string, mixed> */
    private static function block(string $id, string $tone, string $state,
        string $title, string $value): array
    {
        return ['type' => 'notice', 'id' => $id, 'tone' => $tone,
            'state' => $state, 'title' => $title, 'value' => $value];
    }
}
