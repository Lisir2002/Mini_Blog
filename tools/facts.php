<?php
declare(strict_types=1);

/**
 * 计数唯一真源 CLI —— 结构性事实从仓库物理状态计算，文档与守卫锁逐项对账。
 *
 *   php tools/facts.php            输出当前事实集（人读）
 *   php tools/facts.php verify     对账文档锚点 + 守卫硬编码锁 + 反向候选，退出码 0 = 零漂移
 *   php tools/facts.php accept     把当前反向候选写回快照（只写 coverage-baseline.php）
 *   php tools/facts.php json       输出事实集 JSON（供生成器 / 外部工具消费）
 *
 * 存在意义：项目里的 73 接口 / 47 权限点 / 24 核心文件 / 13 契约 / 18 系统层
 * 曾散落在十余个文档与守卫里，每次升级都要手工批量同步，且必然漏（本次审计
 * 就查出 README 同时写着 67 接口与 69 接口、282 守卫与 292 passed）。
 * 现在事实只算一次，其余位置都是「待对账对象」。
 *
 * 边界：verify 不写文件、不改文档、不引导内核、不连库。修不修由人决定，
 * 但「哪里没同步」由这条命令和 26b / 28 / 32 守卫明确报出。
 * 唯一例外是 accept：它只覆盖 tools/facts/coverage-baseline.php 一份快照，
 * 且必须由人显式调用 —— 快照是「已知未纳管」的账本，不能自动续期。
 */

use Wx\Tools\Facts\CodeTable;
use Wx\Tools\Facts\Coverage;
use Wx\Tools\Facts\Facts;
use Wx\Tools\Facts\Reconciler;

$root = require dirname(__DIR__) . '/core/bootstrap.php';
require_once __DIR__ . '/facts/Facts.php';
require_once __DIR__ . '/facts/Reconciler.php';
require_once __DIR__ . '/facts/CodeTable.php';
require_once __DIR__ . '/facts/Coverage.php';

$mode = (string) ($argv[1] ?? 'text');
if (!in_array($mode, ['text', 'json', 'verify', 'accept'], true)) {
    fwrite(STDERR, "用法：php tools/facts.php [text|json|verify|accept]\n");
    exit(2);
}

$facts = Facts::compute($root);

if ($mode === 'json') {
    echo json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

if ($mode === 'text') {
    echo "=== 当前事实集（从仓库物理状态计算） ===\n";
    foreach (Facts::table($facts) as $line) echo $line . "\n";
    echo "\n说明：php_files / total_sloc 为运行快照，来自 docs/responsibility-report.json\n";
    echo "（由 php tests/run.php 每次刷新）。运行 php tools/facts.php verify 对账文档与守卫锁。\n";
    exit(0);
}

$baselineFile = __DIR__ . '/facts/coverage-baseline.php';
$baseline = is_file($baselineFile) ? (array) require $baselineFile : [];
$candidates = Coverage::scan($root, Reconciler::anchors());

if ($mode === 'accept') {
    $keys = array_values(array_unique(array_column($candidates, 'key')));
    sort($keys);
    $body = "<?php\ndeclare(strict_types=1);\n\n"
        . "/**\n"
        . " * 反向锚点的快照 —— 「当前已知未纳管」的候选清单，只用于发现新增。\n"
        . " *\n"
        . " * 为什么不是「全部数字都必须有锚点」：文档里大量数字是史实（版本记录里的\n"
        . " * 「新增 6 个接口」）与快照（性能耗时），要求逐个锚定只会得到几百条噪音，\n"
        . " * 最终没人看。这里退一步：接受现状，只对**新出现的组合**报警。\n"
        . " *\n"
        . " * 条目格式：文件|数值|名词。身份不含行号，改写措辞与上下移行不会触发；\n"
        . " * 同一文件里同一数值同名次出现多次只记一条。\n"
        . " *\n"
        . " * 这个文件是账本，不是许可：新增条目应当先问「该补锚点，还是确属史实」。\n"
        . " * 更新：php tools/facts.php accept（只写本文件）。\n"
        . " */\n"
        . "return [\n";
    foreach ($keys as $k) $body .= "    " . var_export($k, true) . ",\n";
    $body .= "];\n";
    file_put_contents($baselineFile, $body);
    echo "已写入 " . count($keys) . " 条基线 → tools/facts/coverage-baseline.php\n";
    exit(0);
}

$check = Reconciler::run($root, $facts);
$locks = Reconciler::guardLocks($root, $facts);
$codes = CodeTable::run($root);
$cov = Coverage::diff($candidates, $baseline);

echo "=== 计数对账（文档锚点 {$check['checked']} 项 + 守卫锁 + 错误码表 {$codes['checked']} 项"
    . " + 反向候选 " . count($candidates) . " 项） ===\n";
foreach ($check['findings'] as $f) echo '  FAIL  ' . $f . "\n";
foreach ($locks as $l) echo '  FAIL  ' . $l . "\n";
foreach ($codes['findings'] as $f) echo '  FAIL  ' . $f . "\n";
foreach ($cov['added'] as $a) {
    echo "  NEW   {$a['file']} L{$a['line']} 未纳管的「{$a['value']} {$a['noun']}」"
        . "——补锚点，或确认为史实后 accept\n";
    echo "         " . substr($a['excerpt'], 0, 96) . "\n";
}
foreach ($cov['gone'] as $g) echo "  note  基线条目已消失（可 accept 清理）：{$g}\n";

$n = count($check['findings']) + count($locks) + count($codes['findings']) + count($cov['added']);
echo $n === 0
    ? "结果：零漂移（{$check['checked']} 项文档数字 + " . count($locks) . " 项守卫锁 + "
        . "{$codes['checked']} 个错误码 + " . count($candidates) . " 条已知未纳管候选，均与事实一致）\n"
    : "结果：{$n} 处漂移，请同步后重跑\n";
exit($n === 0 ? 0 : 1);
