<?php
declare(strict_types=1);
namespace Wx\Pages\Systems;

/**
 * 运维视图的清单区块 —— 契约、已装单元、未应用迁移、发布历史。
 *
 * 空清单与取不到必须分开：契约空说明容器没绑定（真故障），
 * 发布历史空说明这个站点还没升级过（正常）。两者长成同一张空表就没法判断。
 */
final class SystemsCatalog
{
    /**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    public static function build(array $data): array
    {
        $blocks = [self::contracts($data['contracts'] ?? null), self::units((array) ($data['units'] ?? []))];
        $pending = self::pending($data['release'] ?? null);
        if ($pending !== null) $blocks[] = $pending;
        $blocks[] = self::history($data['history'] ?? null);
        $blocks[] = self::unitProbe((array) ($data['units'] ?? []));
        return $blocks;
    }

    /** @param array<string, mixed>|null $contracts @return array<string, mixed> */
    private static function contracts(?array $contracts): array
    {
        if ($contracts === null) {
            return ['type' => 'notice', 'id' => 'sys-contracts', 'tone' => 'warn',
                'state' => 'unavailable', 'title' => '契约清单不可用',
                'value' => 'system.contracts 调用失败。'];
        }
        $list = array_values(array_map('strval', (array) ($contracts['contracts'] ?? [])));
        $overrides = (array) ($contracts['overrides'] ?? []);
        $rows = [];
        foreach ($list as $name) {
            $short = substr($name, (int) strrpos($name, '\\') + 1);
            $rows[] = ['k' => $short, 'v' => '已绑定', 'ok' => true, 'note' => $name];
        }
        $note = $overrides === [] ? '没有契约覆盖。' : '有 ' . count($overrides) . ' 条覆盖，见下方说明。';
        return ['type' => 'table', 'id' => 'sys-contracts',
            'title' => '契约（' . count($list) . '） · ' . $note, 'rows' => $rows];
    }

    /** @param list<array<string, mixed>> $units @return array<string, mixed> */
    private static function units(array $units): array
    {
        if ($units === []) {
            return ['type' => 'notice', 'id' => 'sys-units', 'tone' => 'warn',
                'state' => 'empty', 'title' => '没有已装单元',
                'value' => 'lifecycle 清单为空：core 单元本身也没登记，说明安装没有走完。'];
        }
        $rows = [];
        foreach ($units as $unit) {
            $rows[] = [
                'k' => (string) ($unit['unit'] ?? ''),
                'v' => (string) ($unit['version'] ?? '?') . ' · ' . (string) ($unit['state'] ?? '?'),
                'ok' => (string) ($unit['state'] ?? '') === 'active',
                'note' => (string) ($unit['updated_at'] ?? '') . (($unit['has_backup'] ?? false) ? ' · 有备份' : ''),
            ];
        }
        return ['type' => 'table', 'id' => 'sys-units',
            'title' => '已装单元（' . count($rows) . '）', 'rows' => $rows];
    }

    /** @param array<string, mixed>|null $release @return array<string, mixed>|null */
    private static function pending(?array $release): ?array
    {
        if ($release === null) return null;
        $pending = array_values(array_map('strval', (array) ($release['pending'] ?? [])));
        if ($pending === []) return null;
        $rows = [];
        foreach ($pending as $file) {
            $rows[] = ['k' => $file, 'v' => '未应用', 'ok' => false, 'note' => 'release.upgrade 时执行'];
        }
        return ['type' => 'table', 'id' => 'sys-pending',
            'title' => '未应用的迁移（' . count($rows) . '）', 'rows' => $rows];
    }

    /**
     * 发布历史 —— release.history 是唯一来源：append-only，每次升级 / 回退一条。
     * 空清单说明站点还没升级过（正常），取不到才是故障，两者必须分开。
     * @param array<string, mixed>|null $history
     * @return array<string, mixed>
     */
    private static function history(?array $history): array
    {
        if ($history === null) {
            return ['type' => 'notice', 'id' => 'sys-history', 'tone' => 'warn',
                'state' => 'unavailable', 'title' => '发布历史不可用',
                'value' => 'release.history 调用失败。'];
        }
        $rows = [];
        foreach ((array) ($history['history'] ?? []) as $h) {
            if (!is_array($h)) continue;
            $result = (string) ($h['result'] ?? '?');
            $migs = count((array) ($h['migrations'] ?? []));
            $rows[] = ['k' => (string) ($h['version'] ?? '?') . ' · ' . (string) ($h['action'] ?? '?'),
                'v' => $result === 'ok' ? '成功' : '失败',
                'ok' => $result === 'ok',
                'note' => (string) ($h['at'] ?? '') . ' · ' . $migs . ' 个迁移'];
        }
        if ($rows === []) {
            return ['type' => 'notice', 'id' => 'sys-history', 'tone' => 'info',
                'state' => 'empty', 'title' => '没有发布历史',
                'value' => '这个站点还没有走过 release.upgrade / rollback，历史为空是正常的。'];
        }
        return ['type' => 'table', 'id' => 'sys-history',
            'title' => '发布历史（' . count($rows) . ' 条，按时间倒序）', 'rows' => $rows];
    }

    /**
     * 单元探查入口：核对摘要与快照列表都是只读 GET，经查询参数触发。
     * 链接在这里集中给出，页面上不存在任何写按钮。
     * @param list<array<string, mixed>> $units
     * @return array<string, mixed>
     */
    private static function unitProbe(array $units): array
    {
        $items = [];
        foreach ($units as $unit) {
            $type = (string) ($unit['type'] ?? '');
            $slug = (string) ($unit['slug'] ?? '');
            if ($type === '' || $slug === '') continue;
            $key = $type . ':' . $slug;
            $items[] = ['label' => '核对 ' . $key, 'href' => '?verify=' . $key];
            $items[] = ['label' => '快照 ' . $key, 'href' => '?releases=' . $key];
        }
        return ['type' => 'links', 'id' => 'sys-probe', 'title' => '单元探查（只读）',
            'items' => $items];
    }
}
