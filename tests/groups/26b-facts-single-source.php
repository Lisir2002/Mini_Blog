<?php
declare(strict_types=1);

use Wx\Tools\Facts\Facts;
use Wx\Tools\Facts\Reconciler;

/**
 * 26b · 计数单一真源守卫 —— 把「升级时忘了同步某个文档数字」变成一次明确的测试失败。
 *
 * 与 tools/facts.php verify 共用同一份事实计算与锚点表，不做第二套实现。
 * 注意：守卫里的硬编码锁（06/13a/13d/14c 的 === 69 / === 46）刻意保留独立，
 * 本组只反向核对「锁没过期」，不把锁改成读事实源 —— 否则就变成自己验自己。
 */
echo "\n=== 26b. 计数单一真源：文档 == 事实，守卫锁未过期 ===\n";

require_once $root . '/tools/facts/Facts.php';
require_once $root . '/tools/facts/Reconciler.php';

check('事实集由物理状态算出：结构量与真实清单 / 文件系统一致', function () use ($root): void {
    $f = Facts::compute($root);
    $reg = json_decode((string) file_get_contents($root . '/api/registry.json'), true);
    truthy($f['operations'] === count($reg['endpoints'] ?? []), '接口数不是从清单算的');
    truthy($f['api_paths'] === count(array_unique(array_column($reg['endpoints'] ?? [], 'path'))), '去重路径算错');
    truthy($f['contracts_files'] === count(glob($root . '/core/Contracts/*.php') ?: []), '契约文件数算错');
    truthy($f['core_files'] === 24 && $f['system_layers'] === 17, '核心 / 系统层结构量异常');
    truthy($f['themes'] === 4 && $f['meta_themes'] === 2, '主题 / 元主题数量异常');
    truthy($f['version'] === json_decode((string) file_get_contents($root . '/core/version.json'), true)['version'],
        '版本号不是从 core/version.json 读的');
    $GLOBALS['wx_facts'] = $f;
});

check('文档锚点零漂移：结构性计数与唯一事实源逐项一致', function () use ($root): void {
    $f = $GLOBALS['wx_facts'] ?? Facts::compute($root);
    $r = Reconciler::run($root, $f);
    truthy($r['checked'] >= 50, '对账锚点过少，可能漏了文件：' . $r['checked']);
    truthy($r['ok'], '文档计数漂移：' . implode(' | ', array_slice($r['findings'], 0, 6)));
});

check('守卫锁未过期：硬编码的 69 / 46 仍等于事实', function () use ($root): void {
    $f = $GLOBALS['wx_facts'] ?? Facts::compute($root);
    $bad = Reconciler::guardLocks($root, $f);
    truthy($bad === [], '守卫锁与事实脱节：' . implode(' | ', $bad));
});

check('工具可重跑：text / json / verify 三种模式都可用且不写文件', function () use ($root): void {
    truthy(is_file($root . '/tools/facts.php'), '缺少 tools/facts.php');
    foreach (['Facts', 'Reconciler'] as $c) {
        truthy(is_file($root . '/tools/facts/' . $c . '.php'), '缺少协作者 ' . $c);
    }
    $cli = (string) file_get_contents($root . '/tools/facts.php');
    truthy(str_contains($cli, "'text', 'json', 'verify'"), 'CLI 模式不全');
    truthy(str_contains($cli, 'file_put_contents') === false, '事实工具不应写文件');
    $json = Facts::compute($root);
    truthy(isset($json['operations'], $json['permissions'], $json['version']), '事实集缺字段');
    truthy($GLOBALS['wx_facts'] === $json, '同一轮里事实集不稳定');
});
