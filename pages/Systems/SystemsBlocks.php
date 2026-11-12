<?php
declare(strict_types=1);
namespace Wx\Pages\Systems;

/**
 * 运维视图主体区块 —— 纯函数，无 IO。
 *
 * 健康状态不是恒定 ok：ok / degraded / down 三档各有颜色与说明，
 * 每一项 check 单独列出，避免「整页绿」掩盖某一项 down。
 * 取数失败与「值就是空」也分开：前者给 unavailable，后者给 empty。
 */
final class SystemsBlocks
{
    /**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    public static function build(array $data): array
    {
        $blocks = [self::health($data['health'] ?? null)];
        $blocks[] = self::info($data['info'] ?? null);
        if ((array) ($data['errors'] ?? []) !== []) $blocks[] = self::errors((array) $data['errors']);
        $blocks[] = self::layers($data['layers'] ?? null);
        $blocks[] = self::release($data['release'] ?? null);
        $blocks[] = self::content($data['content'] ?? null, $data['errors'] ?? []);
        $blocks[] = self::boundary();
        return $blocks;
    }

    /** @param array<string, mixed>|null $health @return array<string, mixed> */
    private static function health(?array $health): array
    {
        if ($health === null) {
            return ['type' => 'notice', 'id' => 'sys-health', 'tone' => 'warn',
                'state' => 'unavailable', 'title' => '健康检查不可用',
                'value' => 'system.health 调用失败，见下方错误汇总。'];
        }
        $status = (string) ($health['status'] ?? 'unknown');
        [$tone, $state, $label] = match ($status) {
            'ok'      => ['ok', 'ok', '正常'],
            'degraded' => ['warn', 'degraded', '降级'],
            'down'    => ['warn', 'down', '不可用'],
            default   => ['warn', 'unknown', '未知'],
        };
        $parts = [];
        foreach ((array) ($health['checks'] ?? []) as $name => $check) {
            if (!is_array($check)) continue;
            $parts[] = $name . '=' . (string) ($check['status'] ?? '?')
                . '(' . (string) ($check['detail'] ?? '') . ')';
        }
        return ['type' => 'notice', 'id' => 'sys-health', 'tone' => $tone, 'state' => $state,
            'title' => '健康状态：' . $label,
            'value' => ($parts === [] ? '没有检查项。' : implode(' · ', $parts))
                . ' · 表 ' . (int) ($health['table_count'] ?? 0) . ' 张 · 系统层 '
                . (int) ($health['systems'] ?? 0) . ' 个 · 主题 '
                . (string) ($health['theme_active'] ?? '(headless)') . '。'
                . ($status === 'degraded' ? '降级不等于可用：有一项不是 ok，但还没有 down。' : '')];
    }

    /** @param array<string, mixed>|null $info @return array<string, mixed> */
    private static function info(?array $info): array
    {
        if ($info === null) {
            return ['type' => 'notice', 'id' => 'sys-info', 'tone' => 'warn',
                'state' => 'unavailable', 'title' => '运行信息不可用',
                'value' => 'system.info 调用失败。'];
        }
        $rows = [
            ['k' => 'core 版本', 'v' => (string) ($info['core_version'] ?? '?'), 'ok' => true,
             'note' => '要求 PHP ' . (string) ($info['requires_php'] ?? '')],
            ['k' => 'PHP', 'v' => (string) ($info['php'] ?? '?'), 'ok' => true, 'note' => '运行版本'],
            ['k' => '接口', 'v' => (string) (int) ($info['api_operations'] ?? 0), 'ok' => true,
             'note' => 'API ' . (string) ($info['api_version'] ?? '')],
            ['k' => '契约', 'v' => (string) (int) ($info['contracts'] ?? 0), 'ok' => true,
             'note' => '容器绑定'],
            ['k' => '数据库', 'v' => (string) ($info['database'] ?? '?'),
             'ok' => (string) ($info['database'] ?? '') === 'mysql',
             'note' => (string) ($info['database'] ?? '') === 'mysql' ? '已连接' : '回退模式'],
            ['k' => '主题', 'v' => (string) ($info['theme_active'] ?? '(headless)'), 'ok' => true,
             'note' => !empty($info['headless']) ? '无呈现层，纯接口' : '已装配呈现层'],
        ];
        return ['type' => 'table', 'id' => 'sys-info', 'title' => '运行信息', 'rows' => $rows];
    }

    /** @param list<string> $errors @return array<string, mixed> */
    private static function errors(array $errors): array
    {
        return ['type' => 'notice', 'id' => 'sys-errors', 'tone' => 'warn',
            'state' => 'degraded', 'title' => '有 ' . count($errors) . ' 项没取到',
            'value' => implode('；', $errors)];
    }

    /** @param array<string, mixed>|null $layers @return array<string, mixed> */
    private static function layers(?array $layers): array
    {
        if ($layers === null) {
            return ['type' => 'notice', 'id' => 'sys-layers', 'tone' => 'warn',
                'state' => 'unavailable', 'title' => '装配顺序不可用',
                'value' => 'system.layers 调用失败。'];
        }
        $order = array_values(array_map('strval', (array) ($layers['order'] ?? [])));
        $rows = [];
        foreach ($order as $i => $name) {
            $rows[] = ['k' => str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) . ' ' . $name,
                'v' => '已装配', 'ok' => true, 'note' => 'manifest 已登记'];
        }
        return ['type' => 'table', 'id' => 'sys-layers',
            'title' => '装配顺序（' . count($order) . ' 层）', 'rows' => $rows];
    }

    /** @param array<string, mixed>|null $release @return array<string, mixed> */
    private static function release(?array $release): array
    {
        if ($release === null) {
            return ['type' => 'notice', 'id' => 'sys-release', 'tone' => 'warn',
                'state' => 'unavailable', 'title' => '发布状态不可用',
                'value' => 'release.status 调用失败。'];
        }
        $schema = (array) ($release['schema'] ?? []);
        $pending = array_values(array_map('strval', (array) ($release['pending'] ?? [])));
        $value = '版本 ' . (string) ($release['version'] ?? '?')
            . ' · 发布于 ' . (string) ($release['released_at'] ?? '?')
            . ' · 已应用迁移 ' . (int) ($schema['applied'] ?? 0)
            . ' 批 / 最新批次 ' . (int) ($schema['latest'] ?? 0);
        if ($pending !== []) {
            $value .= '。有 ' . count($pending) . ' 个迁移未应用：' . implode('、', array_slice($pending, 0, 3))
                . (count($pending) > 3 ? ' 等' : '') . '。';
        } else {
            $value .= '。没有未应用的迁移。';
        }
        return ['type' => 'notice', 'id' => 'sys-release',
            'tone' => $pending === [] ? 'ok' : 'warn',
            'state' => $pending === [] ? 'ok' : 'degraded',
            'title' => '发布状态', 'value' => $value];
    }

    /**
     * 内容规模 —— 取数失败时不再单独渲染一块：错误汇总已经报过一次，
     * 这里只在 stats 真的成功时出现，避免「两块说同一件失败」。
     * @param array<string, int>|null $content @param list<string> $errors
     * @return array<string, mixed>
     */
    private static function content(?array $content, array $errors): array
    {
        if ($content === null) {
            foreach ($errors as $e) {
                if (str_starts_with((string) $e, 'stats：')) return [];
            }
            return ['type' => 'notice', 'id' => 'sys-content', 'tone' => 'info', 'state' => 'empty',
                'title' => '内容规模未知', 'value' => 'content.stats 尚未取到数据。'];
        }
        $published = (int) ($content['published'] ?? 0);
        $total = (int) ($content['total'] ?? 0);
        return ['type' => 'notice', 'id' => 'sys-content', 'tone' => 'info', 'state' => 'ok',
            'title' => '内容规模',
            'value' => '已发布 ' . $published . ' 篇 · 全部 ' . $total . ' 篇（含草稿）· '
                . ($total - $published) . ' 篇未发布。统计与列表接口共享同一口径。'];
    }

    /** @return array<string, mixed> */
    private static function boundary(): array
    {
        return ['type' => 'notice', 'id' => 'sys-boundary', 'tone' => 'info', 'state' => 'ok',
            'title' => '这一页只读',
            'value' => '安装、升级、回滚、卸载仍然只在命令行执行：网页触发文件写入 + 包执行，'
                . '等于把 RCE 面搬进后台。契约、单元、迁移清单在这里只做核对，不做修改。'];
    }
}
