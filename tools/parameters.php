<?php
declare(strict_types=1);

/**
 * 参数级覆盖核对 —— 谁在设置哪个契约参数，哪些参数从来没有写入口。
 *
 *   php tools/parameters.php           全量摘要
 *   php tools/parameters.php --zero    只看待补入口（gap）与未登记
 *   php tools/parameters.php --json    机器可读，供 70 组守卫断言
 *
 * 与 tools/consumers.php 的关系：它回答「operation 有没有人调」，这里回答
 * 「参数有没有人设」。operation 全绿不等于参数全绿 —— v0.53 的三个参数
 * 就是这么漏掉的。登记表 api/parameter-coverage.json 只收「扫描不到证据」
 * 的参数，逐条给出结论与可核对的证据 / 理由；双向核对后未登记必须为零。
 */
require_once __DIR__ . '/audit/ParameterLedger.php';

use Wx\Tools\Audit\ParameterLedger;

$root = dirname(__DIR__);
$args = array_slice($argv, 1);
$json = in_array('--json', $args, true);
$onlyZero = in_array('--zero', $args, true);

$registry = json_decode((string) file_get_contents($root . '/api/registry.json'), true);
$ledgerRaw = json_decode((string) @file_get_contents($root . '/api/parameter-coverage.json'), true);
if (!is_array($ledgerRaw) || !isset($ledgerRaw['declared'])) {
    fwrite(STDERR, "参数级覆盖登记表缺失：api/parameter-coverage.json\n");
    exit(1);
}

$matrix = ParameterLedger::matrix($root);
$scan = ParameterLedger::scan($root, $registry, $matrix);
$bad = ParameterLedger::verify($root, $scan, $ledgerRaw, $matrix);
$declared = (array) $ledgerRaw['declared'];
$unregistered = array_values(array_diff($scan['zero'], array_keys($declared)));

$byState = ['page' => 0, 'cli' => 0, 'gap' => 0];
$gaps = [];
foreach ($declared as $key => $entry) {
    if (!is_array($entry)) continue;
    $state = (string) ($entry['state'] ?? '');
    if (isset($byState[$state])) $byState[$state]++;
    if ($state === 'gap') {
        $gaps[] = ['key' => (string) $key,
            'op' => (string) ($scan['by_key'][$key]['op'] ?? ''),
            'name' => (string) ($scan['by_key'][$key]['name'] ?? ''),
            'note' => (string) ($entry['note'] ?? '')];
    }
}

$result = [
    'total' => count($scan['params']),
    'covered' => count($scan['params']) - count($scan['zero']),
    'zero' => count($scan['zero']),
    'declared' => count($declared),
    'by_state' => $byState,
    'unregistered' => $unregistered,
    'bad_ledger' => $bad,
    'gap' => $gaps,
    'zero_keys' => $scan['zero'],
];
$clean = $bad === [] && $unregistered === [];

if ($json) {
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    exit($clean ? 0 : 1);
}

echo "=== 参数级覆盖 ===\n";
printf("契约参数 %d 个；页面有证据 %d，无证据 %d（登记 %d：page %d / cli %d / gap %d），未登记 %d，坏账 %d\n\n",
    $result['total'], $result['covered'], $result['zero'], $result['declared'],
    $byState['page'], $byState['cli'], $byState['gap'], count($unregistered), count($bad));

echo "--- 待补入口（gap：契约预留，页面没有写入口）---\n";
if ($gaps === []) {
    echo "  无\n";
} else {
    foreach ($gaps as $g) {
        echo '  ' . str_pad($g['key'], 42) . $g['note'] . "\n";
    }
}
echo "\n";

if (!$onlyZero) {
    echo "--- 登记构成 ---\n";
    echo "  page：页面协作者组装入参，扫描器看不见，须附 file + anchor 证据\n";
    echo "  cli ：随 operation 列入刻意零消费，参数由命令行流程构造\n";
    echo "  gap ：契约预留、页面无入口（上面逐条列出）\n\n";
}

if ($unregistered !== []) {
    echo "--- 未登记（无证据且无结论，必须为零）---\n";
    foreach ($unregistered as $key) echo '  ' . $key . "\n";
    echo "\n";
}
if ($bad !== []) {
    echo "--- 坏账 ---\n";
    foreach ($bad as $line) echo '  ' . $line . "\n";
    echo "\n";
}
echo $clean ? "结果：账本干净\n" : "结果：账本有坏账，退出码 1\n";
exit($clean ? 0 : 1);
