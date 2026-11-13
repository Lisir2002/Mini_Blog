<?php
declare(strict_types=1);
namespace Wx\Pages\Stats;

use InvalidArgumentException;

/**
 * 维度下钻 —— stats.series.dimension 的页面入口。
 *
 * 后端对 dimension 是「精确匹配」：串会被规范化成按标签名字母序排列的
 * k=v,k=v，再与存储里的维度列做等值比较。少一个标签就永远查不到 ——
 * 这正是契约旧示例 operation=content.posts 命中不了真实 api_calls 的原因。
 *
 * 本文件只做三件事：
 *  1. 形状校验：键必须在 stats.catalog 声明的标签白名单里、值收窄，且必须
 *     带齐该指标的全部标签；缺一个就显式拒绝，不静默查出一个空图表；
 *  2. 反推完整可命中的串：从 stats.operations 排行的 operation 加上字典里
 *     声明的 code 标签，构造 code=0,operation=xxx；字典没声明就绝不编造入口；
 *  3. 如实说明口径：把「精确匹配 / 必须带齐标签」写进页面文案。
 *
 * 页面层不认识存储与 SQL，规范化规则与采集侧同形（字母序 + 小写 + 收窄字符集）。
 */
final class StatsDimension
{
    public const DEFAULT_METRIC = 'http_requests';
    public const DRILL_METRIC = 'api_calls';
    private const MAX_RAW = 160;
    private const MAX_VALUE = 48;

    /** 指标声明的标签白名单；字典没有该指标时返回 null，绝不编造标签。 */
    public static function labelsOf(array $catalog, string $metric): ?array
    {
        foreach ((array) ($catalog['metrics'] ?? []) as $def) {
            if (!is_array($def) || (string) ($def['name'] ?? '') !== $metric) continue;
            $labels = [];
            foreach ((array) ($def['labels'] ?? []) as $label) {
                $key = strtolower(trim((string) $label));
                if ($key !== '') $labels[] = $key;
            }
            return array_values(array_unique($labels));
        }
        return null;
    }

    /** 指标必须真的在字典里：拿一个查不到的名字下钻等于假入口。 */
    public static function metric(string $raw, array $catalog): string
    {
        $value = strtolower(trim($raw));
        if ($value === '') $value = self::DEFAULT_METRIC;
        if (self::labelsOf($catalog, $value) === null) {
            throw new InvalidArgumentException('metric 不在指标字典里：' . $value);
        }
        return $value;
    }

    /**
     * 校验并规范化维度串；空串表示不分维度（全量）。
     * @return array{dimension: string, labels: array<string,string>, required: list<string>}
     */
    public static function normalize(string $raw, array $catalog, string $metric): array
    {
        $required = self::labelsOf($catalog, $metric) ?? [];
        $value = trim($raw);
        if ($value === '') return ['dimension' => '', 'labels' => [], 'required' => $required];
        if (strlen($value) > self::MAX_RAW) {
            throw new InvalidArgumentException('dimension 过长（上限 ' . self::MAX_RAW . ' 字符）');
        }
        $labels = [];
        foreach (explode(',', $value) as $pair) {
            $pos = strpos($pair, '=');
            if ($pos === false || $pos === 0) {
                throw new InvalidArgumentException('dimension 必须是 key=value,key=value 形式');
            }
            $key = strtolower(trim(substr($pair, 0, $pos)));
            $val = strtolower(trim(substr($pair, $pos + 1)));
            if (!in_array($key, $required, true)) {
                throw new InvalidArgumentException(
                    'dimension 的标签「' . $key . '」不在 ' . $metric . ' 的字典声明里');
            }
            if ($val === '' || strlen($val) > self::MAX_VALUE
                || preg_match('/^[a-z0-9._:-]+$/', $val) !== 1) {
                throw new InvalidArgumentException('dimension 的标签值「' . $val . '」不合法');
            }
            if (isset($labels[$key])) {
                throw new InvalidArgumentException('dimension 的标签「' . $key . '」重复');
            }
            $labels[$key] = $val;
        }
        $missing = array_values(array_diff($required, array_keys($labels)));
        if ($missing !== []) {
            throw new InvalidArgumentException('stats.series 的 dimension 是精确匹配：'
                . $metric . ' 必须带齐全部标签（' . implode('、', $required)
                . '），当前缺少 ' . implode('、', $missing) . '；只写一部分永远查不到采样');
        }
        ksort($labels, SORT_STRING);
        $pairs = [];
        foreach ($labels as $key => $val) $pairs[] = $key . '=' . $val;
        return ['dimension' => implode(',', $pairs), 'labels' => $labels, 'required' => $required];
    }

    /** 从真实 operation 反推 api_calls 的完整维度串；标签超出可反推范围时返回空串。 */
    public static function drillDimension(string $operation, array $catalog): string
    {
        $required = self::labelsOf($catalog, self::DRILL_METRIC);
        if ($required === null || count($required) !== 2
            || !in_array('operation', $required, true) || !in_array('code', $required, true)) {
            return '';
        }
        $op = strtolower(trim($operation));
        if ($op === '' || strlen($op) > self::MAX_VALUE
            || preg_match('/^[a-z0-9._:-]+$/', $op) !== 1) return '';
        return 'code=0,operation=' . $op;
    }

    /**
     * 下钻链接组：第一项回到全量，其余从接口调用排行反推。
     * 当前维度不在预设里时追加一项，绝不把用户悄悄拨回全量。
     * @return list<array{href: string, label: string, active: bool, note: string}>
     */
    public static function links(string $window, string $metric, string $dimension,
        array $ops, array $catalog): array
    {
        $base = '/console/stats?window=' . rawurlencode($window);
        $items = [[
            'href' => $base, 'label' => '全部（不分维度）',
            'active' => $dimension === '', 'note' => '不分维度',
        ]];
        $seen = [];
        foreach ((array) ($ops['items'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $dim = self::drillDimension((string) ($item['operation'] ?? ''), $catalog);
            if ($dim === '' || isset($seen[$dim])) continue;
            $seen[$dim] = true;
            $items[] = [
                'href' => $base . '&metric=' . rawurlencode(self::DRILL_METRIC)
                    . '&dimension=' . rawurlencode($dim),
                'label' => (string) ($item['operation'] ?? ''),
                'active' => $metric === self::DRILL_METRIC && $dimension === $dim,
                'note' => '成功调用 ' . (int) ($item['calls'] ?? 0) . ' 次',
            ];
        }
        $active = array_values(array_filter($items, static fn(array $i): bool => $i['active']));
        if ($dimension !== '' && $active === []) {
            $items[] = [
                'href' => $base . '&metric=' . rawurlencode($metric)
                    . '&dimension=' . rawurlencode($dimension),
                'label' => $dimension, 'active' => true, 'note' => '自定义维度',
            ];
        }
        return $items;
    }

    /** 精确匹配口径说明；字典没给标签时明说页面不编造入口。 */
    public static function caveat(string $metric, array $required): string
    {
        if ($required === []) {
            return $metric . ' 未声明任何标签，dimension 留空即全量；字典没给标签时页面不编造下钻入口。';
        }
        return 'stats.series 的 dimension 是精确匹配：' . $metric
            . ' 必须带齐全部标签（' . implode('、', $required)
            . '），少一个都查不到采样。例如 api_calls 要写 code=0,operation=content.posts，'
            . '只写 operation=content.posts 命中不了真实采样。';
    }
}
