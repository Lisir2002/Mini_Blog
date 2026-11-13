<?php
declare(strict_types=1);

/**
 * 70b · 参数级覆盖 CLI 与两张账本的一致性（运行时）
 *
 * 纯函数与篡改核对在 70 组。本组跑真实 CLI，证明 `--json` / `--zero`
 * 的退出码与输出可用，并核对参数级账本与 operation 级消费矩阵不打架：
 * 参数粒度下沉之后，operation 数、刻意零消费、已消费归属都不许变。
 */
echo "\n=== 70b. 参数级覆盖 CLI 与账本一致性 ===\n";

/** @return array{code: int, out: string} */
$run = static function (string $script, string $flag) use ($root): array {
    $lines = [];
    $code = 0;
    exec('php ' . escapeshellarg($root . '/' . $script) . ' ' . $flag . ' 2>/dev/null', $lines, $code);
    return ['code' => $code, 'out' => implode("\n", $lines)];
};

check('parameters.php --json 退出码 0，结构与计数全部可信',
    function () use ($run): void {
        $res = $run('tools/parameters.php', '--json');
        truthy($res['code'] === 0, '--json 退出码应为 0，实际 ' . $res['code']);
        $d = json_decode($res['out'], true);
        truthy(is_array($d), '--json 输出不是合法 JSON');
        foreach (['total', 'covered', 'zero', 'declared', 'by_state', 'unregistered', 'bad_ledger', 'gap', 'zero_keys'] as $k) {
            truthy(array_key_exists($k, $d), "--json 缺字段 {$k}");
        }
        truthy($d['total'] === 187 && $d['covered'] === 144 && $d['zero'] === 43,
            '参数计数漂移：' . json_encode([$d['total'], $d['covered'], $d['zero']]));
        truthy($d['unregistered'] === [] && $d['bad_ledger'] === [], 'CLI 报出未登记或坏账');
        truthy(count($d['gap']) === 4 && count($d['zero_keys']) === 43, 'gap / 零证据明细条数不符');
        truthy($d['by_state'] === ['page' => 17, 'cli' => 22, 'gap' => 4],
            '三类桶计数漂移：' . json_encode($d['by_state'], JSON_UNESCAPED_UNICODE));
    });

check('--zero 只列待补入口，全量模式给出账本干净结论',
    function () use ($run): void {
        $zero = $run('tools/parameters.php', '--zero');
        truthy($zero['code'] === 0, '--zero 退出码应为 0');
        foreach (['content.posts.pinned', 'content.trash.cursor',
            'stats.series.dimension', 'attachments.download.disposition'] as $key) {
            truthy(str_contains($zero['out'], $key), "--zero 没列出 {$key}");
        }
        truthy(!str_contains($zero['out'], '登记构成'), '--zero 混进了全量模式段落');
        $full = $run('tools/parameters.php', '');
        truthy($full['code'] === 0 && str_contains($full['out'], '结果：账本干净'),
            '全量模式没给出账本干净结论');
        truthy(str_contains($full['out'], '登记构成'), '全量模式缺登记构成说明');
    });

check('operation 级消费矩阵不受参数级账本影响',
    function () use ($run): void {
        $res = $run('tools/consumers.php', '--json');
        truthy($res['code'] === 0, 'consumers.php --json 退出码应为 0');
        $d = json_decode($res['out'], true);
        truthy(is_array($d), 'consumers.php --json 不是合法 JSON');
        truthy($d['total'] === 92, 'operation 数变了，实际 ' . (string) $d['total']);
        truthy(count($d['zero']) === 0, '出现未登记的零消费 operation');
        truthy(count($d['deliberate_zero']) === 10, '刻意零消费不是 10 个');
        foreach (['content.update', 'content.transition'] as $op) {
            truthy(((array) ($d['all'][$op]['consumers'] ?? [])) !== [], $op . ' 的消费方丢了');
        }
    });

check('登记表 JSON 合法，键形如 op.param 且与契约逐一对得上',
    function () use ($root, $run): void {
        $raw = (string) file_get_contents($root . '/api/parameter-coverage.json');
        $ledger = json_decode($raw, true);
        truthy(is_array($ledger) && isset($ledger['declared']), '登记表结构不对');
        truthy(str_contains((string) ($ledger['_note'] ?? ''), '参数'), '登记表缺少口径说明');
        $registry = json_decode((string) file_get_contents($root . '/api/registry.json'), true);
        $known = [];
        foreach ((array) ($registry['endpoints'] ?? []) as $e) {
            foreach ((array) ($e['params'] ?? []) as $p) {
                $known[(string) $e['operation'] . '.' . (string) $p['name']] = true;
            }
        }
        foreach (array_keys((array) $ledger['declared']) as $key) {
            truthy(isset($known[$key]), "登记键不在契约参数全集里：{$key}");
            truthy(substr_count((string) $key, '.') >= 2, "键不是 op.param 形式：{$key}");
        }
    });

check('两张账本口径一致：gap 的 op 都有消费方，cli 的 op 都在刻意零消费里',
    function () use ($root, $run): void {
        $ledger = json_decode((string) file_get_contents($root . '/api/parameter-coverage.json'), true);
        $res = $run('tools/consumers.php', '--json');
        $d = json_decode($res['out'], true);
        $all = (array) ($d['all'] ?? []);
        $deliberate = (array) ($d['deliberate_zero'] ?? []);
        foreach ((array) $ledger['declared'] as $key => $entry) {
            if (!is_array($entry)) continue;
            $op = (string) substr((string) $key, 0, (int) strrpos((string) $key, '.'));
            $state = (string) ($entry['state'] ?? '');
            if ($state === 'gap') {
                truthy(((array) ($all[$op]['consumers'] ?? [])) !== [],
                    "{$key} 标成待补入口，但它的 operation 本身零消费");
            }
            if ($state === 'cli') {
                truthy(isset($deliberate[$op]), "{$key} 标成 cli，operation 却不在刻意零消费里");
            }
        }
    });
