<?php
declare(strict_types=1);

use Wx\Tools\Facts\Coverage;
use Wx\Tools\Facts\Reconciler;

/**
 * 32 · 反向锚点 —— 检测「新出现但没被纳管」的数字。
 *
 * 正向锚点（Reconciler）只能核对「已经想到要核对的句子」。v0.20 起查出的四处漂移
 * 都不是写错，而是**没有锚点**：坏掉时对账仍报零漂移。这组守卫守三件事：
 *   1. 扫描器确实读到文档（零候选等于没生效，不是「干净」）；
 *   2. 新增的未纳管数字必被捕获，已锚定的不得误报；
 *   3. 史实句豁免成立 —— 宁可漏，也不让版本记录把信号淹了。
 *
 * 不守「候选总数不变」：那是快照的职责，改文档就会变，拿它当断言只会假红。
 */
echo "\n=== 32. 反向锚点：未纳管数字的增量检测 ===\n";

require_once $root . '/tools/facts/Reconciler.php';
require_once $root . '/tools/facts/Coverage.php';

check('出厂：扫描器 / 快照 / 复用通道三者在位', function () use ($root): void {
    truthy(is_file($root . '/tools/facts/Coverage.php'), '缺少 Coverage 协作者');
    truthy(is_file($root . '/tools/facts/coverage-baseline.php'), '缺少基线快照');

    $rec = (string) file_get_contents($root . '/tools/facts/Reconciler.php');
    truthy(str_contains($rec, 'public static function anchors'), '锚点表未公开，反向检测无法复用同一张表');

    $cli = (string) file_get_contents($root . '/tools/facts.php');
    truthy(str_contains($cli, 'Coverage::scan'), 'facts CLI 未接入反向扫描');
    truthy(str_contains($cli, "'accept'"), 'facts CLI 缺少快照更新入口');
});

check('当前状态：候选非空，且与快照零差', function () use ($root): void {
    $found = Coverage::scan($root, Reconciler::anchors());
    truthy($found !== [], '扫描零候选：检测器没有生效，这不是「干净」');
    $d = Coverage::diff($found, (array) require $root . '/tools/facts/coverage-baseline.php');
    truthy($d['added'] === [],
        '存在未纳管新增：' . implode(', ', array_column($d['added'], 'key')));
    truthy($d['gone'] === [], '快照条目已消失，应 accept 清理：' . implode(', ', $d['gone']));
});

check('实测：新写入的未纳管数字被捕获，已锚定的不误报', function () use ($root): void {
    $probe = $root . '/docs/zz-coverage-check.md';
    try {
        file_put_contents($probe, "# 探针\n\n本仓库共 18 个系统层。\n");
        $keys = array_column(Coverage::scan($root, Reconciler::anchors()), 'key');
        truthy(in_array('docs/zz-coverage-check.md|18|系统层', $keys, true),
            '新文档里的未纳管数字没有被扫到');

        // 同一句给出锚点后必须不再报：证明「覆盖」判定真的查表，而不是一律放行。
        $anchored = ['docs/zz-coverage-check.md' => ['/本仓库共 (\d+) 个系统层/' => ['system_layers']]];
        $again = array_column(Coverage::scan($root, $anchored), 'key');
        truthy(!in_array('docs/zz-coverage-check.md|18|系统层', $again, true),
            '已锚定的数字仍被当成未纳管');
    } finally {
        @unlink($probe);
    }
});

check('边界：史实句豁免，生成物不参与', function () use ($root): void {
    $probe = $root . '/docs/zz-coverage-check.md';
    try {
        file_put_contents($probe, "# 探针\n\nv0.15 时新增 6 个接口。\n第 12 个系统层最初叫别的名字。\n");
        $keys = array_column(Coverage::scan($root, Reconciler::anchors()), 'key');
        truthy(!in_array('docs/zz-coverage-check.md|6|接口', $keys, true), '版本记录句未被豁免');
        truthy(!in_array('docs/zz-coverage-check.md|12|系统层', $keys, true), '序数句未被豁免');
    } finally {
        @unlink($probe);
    }

    $generated = array_filter(
        array_column(Coverage::scan($root, Reconciler::anchors()), 'key'),
        static fn(string $k): bool => str_starts_with($k, 'docs/api/API-')
    );
    truthy($generated === [], '生成物被纳入扫描，会立刻产生大量噪音：' . implode(', ', $generated));
});
