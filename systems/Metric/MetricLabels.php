<?php
declare(strict_types=1);
namespace Wx\Systems\Metric;

/**
 * 指标标签的规范化 —— 一个指标的一次采样，最终必须落成一个可比较的字符串。
 *
 * 为什么不用 JSON：维度字符串要参与唯一键与 GROUP BY，必须是确定性的。
 * 键按字母序排列、值统一小写并收窄字符集，保证「同样的标签」永远得到同样的键，
 * 否则同一批数据会被拆成多行，统计结果会静默偏低。
 */
final class MetricLabels
{
    private const MAX_VALUE = 48;

    /** @param array<string, mixed> $labels @return string 形如 method=GET,status=200 */
    public static function format(array $labels): string
    {
        $pairs = [];
        foreach ($labels as $key => $value) {
            $pairs[] = self::key((string) $key) . '=' . self::value((string) $value);
        }
        sort($pairs, SORT_STRING);
        return implode(',', $pairs);
    }

    /** @return array<string, string> */
    public static function parse(string $dimension): array
    {
        $out = [];
        foreach (explode(',', $dimension) as $pair) {
            if ($pair === '') continue;
            $pos = strpos($pair, '=');
            if ($pos === false) continue;
            $out[substr($pair, 0, $pos)] = substr($pair, $pos + 1);
        }
        return $out;
    }

    /** 标签键由代码给出，不来自请求；这里只做最后一道形状校验。 */
    public static function key(string $key): string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9_]/', '_', $key) ?? '';
        return $key === '' ? 'label' : substr($key, 0, 24);
    }

    /**
     * 标签值来自运行期（状态码、operation、状态名），必须收窄：
     * 小写、只保留 [a-z0-9._:-]、最长 48 字符。空值显式记为 none，
     * 避免空串与缺省值在维度字符串里混成同一行。
     */
    public static function value(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._:-]/', '_', $value) ?? '';
        if ($value === '') return 'none';
        return substr($value, 0, self::MAX_VALUE);
    }
}
