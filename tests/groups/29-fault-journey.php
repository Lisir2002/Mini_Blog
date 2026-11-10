<?php
declare(strict_types=1);

use Wx\Tools\Faults\FaultJourney;

/**
 * 29 · 故障旅程守卫 —— 断言「坏的时候不骗人」这条承诺是可执行的。
 *
 * 与 tools/faults.php 共用同一个 FaultJourney::run()：守卫不重写一遍故障注入，
 * 跑的是完全相同的请求序列。成功路径由 26a 守，这里只管反例。
 */
echo "\n=== 29. 故障旅程：依赖缺失 / 凭据过期 / 并发碰撞 / 容量越界 ===\n";

foreach (['Site', 'Probe', 'Server', 'JourneyState', 'InstallStage', 'ContentStage', 'ThemeStage', 'Journey'] as $c) {
    require_once $root . '/tools/journey/' . $c . '.php';
}
foreach (['FaultJourney', 'DataFaults', 'AuthFaults', 'RequestFaults', 'StartupFaults'] as $c) {
    require_once $root . '/tools/faults/' . $c . '.php';
}

check('故障工具出厂：CLI 与四个协作者齐备，支持 text / json 两种输出', function () use ($root): void {
    truthy(is_file($root . '/tools/faults.php'), '缺少 tools/faults.php');
    foreach (['FaultJourney', 'DataFaults', 'AuthFaults', 'RequestFaults', 'StartupFaults'] as $c) {
        truthy(is_file($root . '/tools/faults/' . $c . '.php'), '缺少协作者 ' . $c);
    }
    $cli = (string) file_get_contents($root . '/tools/faults.php');
    truthy(str_contains($cli, "'text', 'json'"), 'CLI 未提供 text / json 双模式');
    truthy(str_contains($cli, 'FaultJourney::run('), 'CLI 未通过编排器执行');
    truthy(preg_match('/new\s+Kernel\s*\(/', $cli) === 0, 'CLI 自己 new Kernel，绕过统一入口与异常边界');
});

check('复用而非复制：隔离站点与探针来自 tools/journey/，不另建一套', function () use ($root): void {
    $fj = (string) file_get_contents($root . '/tools/faults/FaultJourney.php');
    truthy(str_contains($fj, 'new Site(') && str_contains($fj, 'new Probe('),
        '未复用 Journey 的隔离站点 / 探针');
    truthy(str_contains($fj, 'sys_get_temp_dir()') === false && str_contains($fj, 'mkdir(') === false,
        '故障旅程自己建目录了，说明复制了一套隔离逻辑');
    truthy(str_contains($fj, '} finally {') && str_contains($fj, 'cleanup()'), '清理未放在 finally 里');
});

/** 跑一次真实故障旅程，返回结构化结果。 */
$runFaults = static fn(): array => FaultJourney::run($root);

check('真实执行：注入的故障全部被正确识别，无一步「安静地错」', function () use ($runFaults): void {
    $r = $runFaults();
    truthy($r['failed'] === 0, '故障旅程存在失败步骤：' . implode(' | ', array_filter(
        $r['lines'], static fn(string $l): bool => str_starts_with(ltrim($l), 'FAIL'))));
    truthy($r['passed'] >= 18, '故障场景数不足：' . $r['passed']);
    $text = implode("\n", $r['lines']);
    foreach (['数据库不可达', '数据库恢复', '附件字节被删', '令牌过期', '同键进行中',
              '业务失败按幂等语义重放', '上传超限', '重复 slug', '主题指向不存在'] as $scene) {
        truthy(str_contains($text, $scene), '故障旅程缺少场景：' . $scene);
    }
    truthy($r['residue'] === [], '工作区出现残留：' . implode(', ', $r['residue']));
    truthy(($r['facts']['cleanup'] ?? '') === 'ok', '隔离站点未被清理');
});

check('关键结论守在守卫里：错误可分支，故障不固化', function () use ($root): void {
    $data = (string) file_get_contents($root . '/tools/faults/DataFaults.php');
    truthy(str_contains($data, '50001'), '附件字节缺失未断言具体错误码');
    $auth = (string) file_get_contents($root . '/tools/faults/AuthFaults.php');
    truthy(str_contains($auth, '40102'), '令牌过期未断言 40102（与未登录区分）');
    truthy(str_contains($auth, '40905'), '同键进行中未断言 40905');
    truthy(str_contains($auth, 'Idempotency-Replayed'), '未验证重放标记');
    $req = (string) file_get_contents($root . '/tools/faults/RequestFaults.php');
    truthy(str_contains($req, '40901'), '重复 slug 未断言 40901');
    truthy(str_contains($req, 'degraded'), '主题不可解析未断言 degraded');
});

check('不越界：故障旅程不新增系统层 / 接口 / 契约，core/ 仍 24 文件', function () use ($root): void {
    truthy(count(glob($root . '/core/*.php') ?: []) + count(glob($root . '/core/*/*.php') ?: []) === 24,
        'core/ 文件数被改动');
    truthy(count(glob($root . '/systems/*/system.json') ?: []) === 18, '系统层数量被改动');
    $reg = json_decode((string) file_get_contents($root . '/api/registry.json'), true);
    truthy(count($reg['endpoints'] ?? []) === 80, '接口清单被改动');
});
