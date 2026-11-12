<?php
declare(strict_types=1);

use Wx\Core\Http\Request;

/**
 * 65 · 升级可观测运行时：history / verify / releases / task 的只读探查
 *
 * /console/system 从 v0.48 起补齐「升级面」的可观测区块，全部是只读 GET：
 *  1. release.history 把「有没有升级过」从 release.status 的单条 last 扩成完整表；
 *  2. lifecycle.verify 按单元核对 SHA-256 摘要，篡改文件必须上页（mode no-manifest 单说）；
 *  3. lifecycle.releases 列快照（回退的目标版本清单，本身不执行回退）；
 *  4. state.task.status 按任务 id 查相位，查不到与格式非法分开说；
 *  5. 探查入口是链接（GET 查询参数），页面上不存在任何写按钮；
 *  6. 非法单元 / 非法任务 id 在取数前 fail-closed，不把输入错误当成故障。
 */
echo "\n=== 65. 升级可观测运行时 ===\n";

$site   = tempSite('probe-runtime');
$prefix = 'q65_';
dropTables($prefix);
$k = isolatedKernel($site, $prefix, [
    'theme' => ['active' => 'glass'],
    'permission' => ['cache' => ['driver' => 'memory']],
]);
$k->boot();

$hit = static function (string $method, string $path, array $q = [], array $headers = [])
    use ($k): array {
    $res = $k->handle(new Request($method, $path, $q, $headers, []));
    return [$res->status(), $res->body(), $res->headers()];
};
$admin = bearer('test-admin-token');
$get = static fn(array $q): array => $hit('GET', '/console/system', $q, $admin);

check('匿名 401；GET 之外的方法明确拒绝，页面上没有写按钮',
    function () use ($hit, $admin): void {
        [$anon] = $hit('GET', '/console/system');
        truthy($anon === 401, '匿名应 401，实际 ' . $anon);
        [$post, , $heads] = $hit('POST', '/console/system', [], $admin);
        truthy($post === 405 && str_contains((string) ($heads['Allow'] ?? ''), 'GET'),
            'POST 应 405 且 Allow 只给 GET/HEAD');
        [, $page] = $hit('GET', '/console/system', [], $admin);
        truthy(str_contains($page, '这一页只读'), '只读边界说明缺失');
        truthy(!str_contains($page, 'name="_form_ticket"'), '只读页不该出现表单票据');
    });

check('默认页有发布历史区块与单元探查链接；空历史单独说明',
    function () use ($get): void {
        [$s, $page] = $get([]);
        truthy($s === 200, '应 200，实际 ' . $s);
        truthy(str_contains($page, '发布历史'), '历史区块缺失');
        truthy(str_contains($page, '没有走过 release.upgrade / rollback'),
            '空历史没有单独说明（把空态当故障）');
        truthy(str_contains($page, '核对 core:core'), '没有核对链接');
        truthy(str_contains($page, '快照 core:core'), '没有快照链接');
    });

check('完整性核对：no-manifest 明说「未比对」，不把没核对写成绿灯',
    function () use ($get): void {
        [, $page] = $get(['verify' => 'core:core']);
        truthy(str_contains($page, '完整性核对：core:core'), 'verify 区块缺失');
        // 新装单元没有 manifest.sha256：必须明说「未做摘要比对」，不得显示「全部匹配」。
        truthy(str_contains($page, '未做摘要比对'), 'no-manifest 没有说清未比对');
        truthy(!str_contains($page, '摘要全部匹配'), '把没核对伪装成了绿灯');
        truthy((bool) preg_match('/扫描 \d+ 个文件/', $page), '扫描文件数缺失');
    });

check('快照列表：有快照给版本与字节，没快照说清「从未快照、回退无目标」',
    function () use ($get): void {
        [, $page] = $get(['releases' => 'core:core']);
        truthy(str_contains($page, '快照列表'), '快照区块缺失');
        // 新站点没有快照：空态必须可判定，而不是一张空表。
        truthy((bool) preg_match('/没有这个单元的快照|快照列表（\d+）/', $page),
            '快照空态与计数没有分开表达');
        truthy(!preg_match('/name="version"/', $page), '快照页不该出现可提交的版本输入');
    });

check('长任务查询：不存在的任务与非法 id 分开说，都能落到页面上',
    function () use ($get): void {
        [, $page] = $get(['task' => 'never-created-1']);
        truthy(str_contains($page, 'task：'), '不存在的任务没有给说明');
        [, $page2] = $get(['task' => 'bad id!']);
        truthy(str_contains($page2, '1–64 位字母、数字、下划线或短横线'),
            '非法 id 没有解释格式要求');
    });

check('非法单元在取数前 fail-closed：白名单、slug 格式与长度都挡住',
    function () use ($get): void {
        [, $page] = $get(['verify' => 'evil:x']);
        truthy(str_contains($page, 'type 只能是'), '类型白名单没有解释');
        [, $page2] = $get(['releases' => 'core:' . str_repeat('a', 100)]);
        truthy(str_contains($page2, '1–64 位'), '过长 slug 没有被挡');
        [, $page3] = $get(['verify' => 'system:User:extra']);
        truthy(str_contains($page3, 'type:slug'), '三段式输入没有识别');
        [, $page4] = $get(['verify' => 'system:UPPER']);
        truthy(str_contains($page4, 'type:slug'), '大写 slug 没有被挡');
    });

check('探查失败与真实故障分开：探查错误不吞掉取数降级信息',
    function () use ($get): void {
        [, $page] = $get(['verify' => 'system:GhostUnit']);
        truthy(str_contains($page, 'verify：'), '探查失败没有进入错误汇总');
        truthy(str_contains($page, '运行信息'), '探查失败把整个页面拖垮了');
    });
