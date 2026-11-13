<?php
declare(strict_types=1);
namespace Wx\Tools\Audit;

/**
 * 参数级覆盖账本 —— 消费矩阵只看 operation 名，看不见参数。
 *
 * v0.53 暴露的盲点：`content.transition` / `content.update` 一直显示「已消费」，
 * 但 publish_at / pinned / weight 从来没有任何页面能设置。这里把粒度下沉到
 * registry.json 的每一条 params[]：先复用 consumers.php 给出的消费方文件列表，
 * 再在这些文件里找参数名的字面量证据。找不到的必须逐条登记结论，不许沉默。
 *
 * 登记表三种 state 刻意区分：
 *  - page：页面确实消费，但入参组装委托给同目录协作者（职责预算拆分），
 *          扫描器在「调用方文件」里看不见；必须给出可核对的 file + anchor；
 *  - cli ：随 operation 列入刻意零消费（装配期 / 升级动作），由命令行构造；
 *  - gap ：契约预留、页面至今没有写入口 —— 这是账本真正要暴露的存量缺口，
 *          `--zero` 只列这一段，它同时是下一版的候选清单。
 *
 * 边界：字符串字面量只能证明「同名出现过」，分不清入参与出参、也分不清
 * 别的 operation 的同名参数。所以登记表是权威结论，工具只做双向核对：
 * 登记了有证据的参数是坏账，有零证据却没登记同样是坏账。
 */
final class ParameterLedger
{
    public const STATES = ['page', 'cli', 'gap'];

    /** 消费方矩阵是 operation 级唯一真源；参数级不重复实现扫描器。 */
    public static function matrix(string $root): array
    {
        $cmd = 'php ' . escapeshellarg($root . '/tools/consumers.php') . ' --json';
        $decoded = json_decode((string) shell_exec($cmd), true);
        if (!is_array($decoded) || !isset($decoded['all'])) {
            throw new \RuntimeException('消费矩阵不可用：' . $cmd);
        }
        return $decoded;
    }

    /** @return array{params: list<array<string,mixed>>, by_key: array<string,array<string,mixed>>, zero: list<string>} */
    public static function scan(string $root, array $registry, array $matrix): array
    {
        $all = (array) ($matrix['all'] ?? []);
        $params = [];
        $byKey = [];
        foreach ((array) ($registry['endpoints'] ?? []) as $endpoint) {
            $op = (string) ($endpoint['operation'] ?? '');
            if ($op === '') continue;
            $files = (array) ($all[$op]['consumers'] ?? []);
            foreach ((array) ($endpoint['params'] ?? []) as $param) {
                $name = (string) ($param['name'] ?? '');
                if ($name === '') continue;
                $key = $op . '.' . $name;
                $hits = [];
                foreach ($files as $file) {
                    $path = $root . '/' . (string) $file;
                    if (!is_file($path)) continue;
                    $src = (string) file_get_contents($path);
                    if (str_contains($src, "'" . $name . "'")
                        || str_contains($src, '"' . $name . '"')) $hits[] = (string) $file;
                }
                $row = ['key' => $key, 'op' => $op, 'name' => $name,
                    'in' => (string) ($param['in'] ?? ''), 'files' => $files,
                    'hits' => array_values(array_unique($hits))];
                $params[] = $row;
                $byKey[$key] = $row;
            }
        }
        $zero = array_values(array_keys(array_filter($byKey,
            static fn(array $r): bool => $r['hits'] === [])));
        return ['params' => $params, 'by_key' => $byKey, 'zero' => $zero];
    }

    /** @return list<string> 坏账描述；空数组 = 账本干净 */
    public static function verify(string $root, array $scan, array $ledger, array $matrix): array
    {
        $declared = (array) ($ledger['declared'] ?? []);
        $deliberate = array_fill_keys(array_keys((array) ($matrix['deliberate_zero'] ?? [])), true);
        $zeroSet = array_fill_keys($scan['zero'], true);
        $bad = [];

        foreach ($declared as $key => $entry) {
            $key = (string) $key;
            if (!isset($scan['by_key'][$key])) {
                $bad[] = "登记了不存在的参数：{$key}";
                continue;
            }
            if (!isset($zeroSet[$key])) {
                $bad[] = "该参数已有页面证据，不该登记：{$key}";
                continue;
            }
            if (!is_array($entry)) {
                $bad[] = "登记项必须是对象：{$key}";
                continue;
            }
            $state = (string) ($entry['state'] ?? '');
            if (!in_array($state, self::STATES, true)) {
                $bad[] = "state 只能是 page / cli / gap：{$key}";
                continue;
            }
            if (self::chars((string) ($entry['note'] ?? '')) < 20) {
                $bad[] = "理由至少 20 字：{$key}";
            }
            $row = $scan['by_key'][$key];
            $op = (string) $row['op'];
            if ($state === 'cli' && !isset($deliberate[$op])) {
                $bad[] = "state=cli 的 operation 必须列入刻意零消费：{$key}";
            }
            if ($state === 'gap' && isset($deliberate[$op])) {
                $bad[] = "刻意零消费的 operation 不该登记为待补入口：{$key}";
            }
            if ($state === 'page') {
                $file = (string) ($entry['file'] ?? '');
                $anchor = (string) ($entry['anchor'] ?? '');
                if ($file === '' || $anchor === '') {
                    $bad[] = "state=page 需要 file 与 anchor：{$key}";
                    continue;
                }
                if (!self::inScope($file, (array) $row['files'])) {
                    $bad[] = "证据文件不在该接口的页面协作者目录内：{$key} -> {$file}";
                    continue;
                }
                $path = $root . '/' . $file;
                if (!is_file($path)) {
                    $bad[] = "证据文件不存在：{$key} -> {$file}";
                    continue;
                }
                if (!str_contains((string) file_get_contents($path), $anchor)) {
                    $bad[] = "证据锚点在文件里找不到：{$key} -> {$anchor}";
                }
            }
        }
        foreach ($scan['zero'] as $key) {
            if (!isset($declared[$key])) $bad[] = "参数没有页面证据且未登记：{$key}";
        }
        return $bad;
    }

    /** 证据文件只认「该 operation 的消费方所在页面目录」的协作者，防止拿不相干文件凑数。 */
    private static function inScope(string $file, array $consumerFiles): bool
    {
        foreach ($consumerFiles as $f) {
            if (preg_match('#^(pages/[^/]+|themes/[^/]+/assets)/#', (string) $f, $m) === 1
                && str_starts_with($file, $m[1] . '/')) return true;
        }
        return false;
    }

    private static function chars(string $value): int
    {
        return preg_match_all('/./us', $value) ?: 0;
    }
}
