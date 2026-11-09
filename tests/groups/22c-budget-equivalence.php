<?php
declare(strict_types=1);

use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Plugins\Performance\PerformanceBudget;

/**
 * 22c · 预算与开关等价（规矩 T 第三条：不隐形）。
 *
 * 预算只判定与留痕，不改业务结果；关闭时一个钩子都不注册；
 * 开关前后的 body 逐字相同（首页是稳定响应，不含每请求随机值）。
 */
echo "\n=== 22c. 性能预算 / 开关语义等价 ===\n";

check('预算判定：等于阈值不算超限，超项分别留名', function (): void {
    $budget = new PerformanceBudget(10, 50.0);

    $ok = $budget->verdict(10, 50.0);
    truthy($ok['exceeded'] === false && $ok['reasons'] === [], '边界值不应判超限');

    $many = $budget->verdict(11, 1.0);
    truthy($many['reasons'] === ['queries'], '只有查询数超限时不应报 sql_ms');

    $slow = $budget->verdict(1, 51.0);
    truthy($slow['reasons'] === ['sql_ms'], '只有耗时超限时不应报 queries');

    $both = $budget->verdict(99, 99.0);
    truthy($both['reasons'] === ['queries', 'sql_ms'], '两项都应被记录');

    $timing = $budget->serverTiming($many);
    truthy(str_contains($timing, '11 queries') && str_contains($timing, 'sql;'), 'Server-Timing 不可读');
});

check('预算超限必留痕：X-Wx-Budget 头 + kernel.trace 事件', function () use ($root): void {
    $cfg = ['performance' => [
        'enabled' => true,
        'budget'  => ['max_queries' => 0, 'max_ms' => 0.0],
        'economy' => ['min_bytes' => 64],
    ]];
    $k = new Kernel($root, $cfg);
    $seen = [];
    $k->hooks()->on('kernel.trace', static function (string $message, array $context = []) use (&$seen): void {
        if ($message === '性能预算超限') $seen[] = $context;
    });

    $r = $k->handle(new Request('GET', '/', [], []));
    truthy(count($seen) === 1, '预算超限应恰好留痕一次，实际 ' . count($seen));
    truthy(isset($seen[0]['queries'], $seen[0]['max_queries']), '留痕缺少计数与阈值');
    truthy(str_contains((string) ($r->headers()['X-Wx-Budget'] ?? ''), 'queries'), '响应头未标出超限原因');
    truthy($r->body() !== '', '预算留痕不得改写业务响应');
});

check('开关语义等价：关闭零钩子、开启有钩子，body 逐字相同', function () use ($root): void {
    $offKernel = new Kernel($root, ['performance' => ['enabled' => false]]);
    $onKernel = new Kernel($root, ['performance' => ['enabled' => true]]);
    // 插件钩子在 boot() 阶段由插件系统装载，先看钩子就必须先装配。
    $offKernel->boot();
    $onKernel->boot();

    $offActions = $offKernel->hooks()->tags()['actions'];
    $onActions = $onKernel->hooks()->tags()['actions'];
    truthy(!in_array('kernel.shutdown', $offActions, true), '关闭时不应注册任何增强钩子');
    truthy(in_array('kernel.shutdown', $onActions, true), '开启时应挂在渲染后出口');

    $off = $offKernel->handle(new Request('GET', '/', [], []));
    $on = (new Kernel($root, ['performance' => ['enabled' => true]]))
        ->handle(new Request('GET', '/', [], []));
    truthy($off->body() === $on->body(), '开关不得改变业务 body');
    truthy($off->status() === $on->status(), '开关不得改变状态码');
    truthy(isset($on->headers()['Server-Timing']), '开启后每个响应应自证工作量');
});
