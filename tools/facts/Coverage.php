<?php
declare(strict_types=1);
namespace Wx\Tools\Facts;

/**
 * 反向锚点 —— 正向锚点只能核对「已经想到要核对的句子」。
 *
 * v0.20 起陆续发现四处漂移（README 徽章 282、SPEC 漏登 5 个错误码、
 * architecture.html 页眉「14 系统层 · 55 接口」、结构树「40 个分组 200 项守卫」），
 * 共同点不是写错，而是**没有锚点**：坏掉时对账仍报「零漂移」。
 *
 * 这里补上另一半：扫描文档里「有单一真源的名词」前的数字，凡未被任一锚点覆盖、
 * 又不属于史实句，就视为候选。候选不与绝对值门禁比对 —— 那会立刻产生 600+ 条噪音；
 * 而是与一份快照（coverage-baseline.php）比对，只对**新出现的组合**报警。
 *
 * 三个边界，刻意收窄：
 *   1. 只认有唯一真值的名词（接口 / operation / 系统层 / 契约接口 / 权限点 / 路径 /
 *      分组 / PHP 文件 / 有效代码行）。性能数字、行号、示例值不在此列 —— 它们没有
 *      唯一答案，锚定只会制造假红；
 *   2. 生成物（docs/api/API-*.md）不参与：由生成器保证一致，另有守卫核零漂移；
 *   3. 史实句豁免（版本号 / 序数 / 「当时」「最初」等），历史记录不要求锚定。
 *
 * 身份用「文件 + 数值 + 名词」而不是行号：改写措辞、上下移行都不该触发，
 * 只有「这个文件里第一次出现这个数」才值得看一眼。
 */
final class Coverage
{
    /** 长名词必须先匹配，否则「契约接口」会被「接口」截断。 */
    private const NOUNS = [
        '契约接口', '有效代码行', 'PHP 文件', '系统层', '权限点', 'operation', '路径', '分组', '接口',
    ];

    /** 史实行：版本号、序数、时间状语。命中即豁免，宁可漏也不吵。 */
    private const HISTORIC = '/(?:v0\.\d|第\s*\d+\s*(?:个|层)|曾|当时|最初|此前|历史上|早期|原本|一度)/u';

    /** 生成物不参与：另有 gen-api-docs 零漂移守卫。 */
    private const GENERATED = '#^docs/api/API-#';

    /**
     * @param array<string, array<string, list<string>>> $anchors
     * @return list<array{key:string, file:string, line:int, value:string, noun:string, excerpt:string}>
     */
    public static function scan(string $root, array $anchors): array
    {
        $re = '/\d[\d,]*(?:\.\d+)?\s*(?:个|条|份|层|组|项)?\s*(' . implode('|', self::NOUNS) . ')/u';
        $out = [];

        foreach (self::documents($root) as $rel) {
            $src = (string) @file_get_contents($root . '/' . $rel);
            if ($src === '') continue;

            $spans = self::spans($src, $anchors[$rel] ?? []);
            if (preg_match_all($re, $src, $m, PREG_OFFSET_CAPTURE) === 0) continue;

            foreach ($m[0] as $i => [$text, $off]) {
                if (self::covered($off, strlen($text), $spans)) continue;

                $lineText = self::lineAt($src, $off);
                if (preg_match(self::HISTORIC, $lineText)) continue;

                $value = preg_replace('/^([\d,]+(?:\.\d+)?).*$/su', '$1', $text);
                // 计数不会是小数：`2.1 接口` 是章节编号，`3.7 ms` 是性能值，
                // 两者都不该被当成「N 个接口」，否则标题层级一改就假红。
                if (str_contains($value, '.')) continue;
                $noun = $m[1][$i][0];
                $out[] = [
                    'key'     => $rel . '|' . str_replace(',', '', $value) . '|' . $noun,
                    'file'    => $rel,
                    'line'    => substr_count(substr($src, 0, $off), "\n") + 1,
                    'value'   => $value,
                    'noun'    => $noun,
                    'excerpt' => trim(preg_replace('/\s+/u', ' ', $lineText)),
                ];
            }
        }
        return $out;
    }

    /**
     * @param list<array{key:string}> $found
     * @param list<string> $baseline
     * @return array{added:list<array<string,mixed>>, gone:list<string>}
     */
    public static function diff(array $found, array $baseline): array
    {
        $known = array_fill_keys($baseline, true);
        $added = [];
        $seen = [];
        foreach ($found as $f) {
            $seen[$f['key']] = true;
            if (!isset($known[$f['key']])) $added[] = $f;
        }
        return ['added' => $added, 'gone' => array_values(array_diff(array_keys($known), array_keys($seen)))];
    }

    /** @param array<string, list<string>> $patterns */
    private static function spans(string $src, array $patterns): array
    {
        $spans = [];
        foreach ($patterns as $pattern => $keys) {
            if (preg_match_all($pattern, $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) continue;
            foreach ($m as $hit) {
                foreach ($hit as $g) {
                    if (!is_array($g) || $g[1] < 0) continue;
                    $spans[] = [$g[1], $g[1] + strlen($g[0])];
                }
            }
        }
        return $spans;
    }

    private static function covered(int $from, int $len, array $spans): bool
    {
        foreach ($spans as [$a, $b]) {
            if ($from >= $a && $from + $len <= $b) return true;
        }
        return false;
    }

    private static function lineAt(string $src, int $off): string
    {
        $start = strrpos(substr($src, 0, $off), "\n");
        $start = $start === false ? 0 : $start + 1;
        $end = strpos($src, "\n", $off);
        return substr($src, $start, ($end === false ? strlen($src) : $end) - $start);
    }

    /** @return list<string> */
    private static function documents(string $root): array
    {
        $out = ['README.md'];
        $dir = $root . '/docs';
        if (!is_dir($dir)) return $out;

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            $rel = 'docs/' . substr($f->getPathname(), strlen($dir) + 1);
            if (!preg_match('/\.(md|html)$/', $rel)) continue;
            if (preg_match(self::GENERATED, $rel)) continue;
            $out[] = $rel;
        }
        sort($out);
        return array_values(array_unique($out));
    }
}
