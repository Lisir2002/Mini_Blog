<?php
declare(strict_types=1);

use Wx\Contracts\ApiAuthenticatorInterface;
use Wx\Contracts\ContentRepositoryInterface;
use Wx\Contracts\DatabaseInterface;
use Wx\Contracts\LoggerInterface;
use Wx\Core\Container;
use Wx\Core\HookBus;
use Wx\Core\Http\Request;

echo "\n=== 4. 钩子、契约与系统层装配 ===\n";

check('filter 按 priority 升序链式执行', function (): void {
    $bus = new HookBus();
    $bus->filter('x', fn($v) => $v . 'B', 10);
    $bus->filter('x', fn($v) => $v . 'A', 5);
    $bus->filter('x', fn($v) => $v . 'C', 20);
    truthy($bus->apply('x', '') === 'ABC', '优先级顺序错误：' . $bus->apply('x', ''));
});

check('契约可被更高 priority 显式覆盖，且留下痕迹', function (): void {
    $c = new Container();
    $c->bind(LoggerInterface::class, fn() => new stdClass(), 'a', 10);
    $c->bind(LoggerInterface::class, fn() => new stdClass(), 'b', 20);
    truthy($c->ownerOf(LoggerInterface::class) === 'b', '覆盖未生效');
    truthy(count($c->overrides()) === 1, '覆盖未记录');
});

check('同级 priority 的冲突绑定被拒绝（不静默覆盖）', function (): void {
    $c = new Container();
    $c->bind(LoggerInterface::class, fn() => new stdClass(), 'a', 10);
    $threw = false;
    try {
        $c->bind(LoggerInterface::class, fn() => new stdClass(), 'b', 10);
    } catch (RuntimeException) {
        $threw = true;
    }
    truthy($threw, '冲突绑定应抛出 RuntimeException');
});

check('14 个系统层自动发现，依赖拓扑有序', function (): void {
    $k = kernel(null);
    $k->boot();
    $order = $k->systems()->order();
    truthy(count($order) === 14, '系统层数量异常：' . count($order) . ' → ' . implode(',', $order));
    $pos = array_flip($order);
    truthy($pos['log'] < $pos['security'], 'log 应先于 security');
    truthy($pos['database'] < $pos['content'], 'database 应先于 content');
    truthy($pos['security'] < $pos['user'], 'security 应先于 user');
    truthy($pos['user'] < $pos['permission'] && $pos['permission'] < $pos['api'],
        'permission 应位于 user 之后、api 之前');
    truthy($pos['plugin'] < $pos['content'], 'plugin 应先于 content');
    truthy($pos['content'] < $pos['theme'], 'content 应先于 theme');
    truthy($pos['theme'] < $pos['page'], 'theme 应先于 page');
});

check('第 11 个系统层 content 自动接入并绑定内容契约', function (): void {
    $k = kernel(null);
    $k->boot();
    truthy(in_array('content', $k->systems()->order(), true), 'content 未被自动发现');
    truthy($k->container()->has(ContentRepositoryInterface::class), 'ContentRepositoryInterface 未绑定');
    truthy($k->container()->has(ApiAuthenticatorInterface::class), 'ApiAuthenticatorInterface 未绑定');
    truthy($k->container()->has(DatabaseInterface::class), 'DatabaseInterface 未绑定');
});

check('独立页自带路由，核心无内置路由', function (): void {
    $r = kernel(null)->handle(new Request('GET', '/links'));
    $data = json_decode($r->body(), true);
    truthy(($data['page'] ?? null) === 'links', '/links 未被独立页包接管');
    truthy(($data['title'] ?? null) === '友情链接', '独立页数据不正确');
});

check('安全系统通过钩子注入 HTTP 头', function (): void {
    $r = kernel(null)->handle(new Request('GET', '/'));
    truthy(($r->headers()['X-Content-Type-Options'] ?? null) === 'nosniff', '安全头缺失');
});

check('日志系统接管内核可观测性出口', function () use ($root): void {
    $file = $root . '/storage/logs/' . date('Y-m-d') . '.log';
    truthy(is_file($file), '未生成日志文件');
    truthy(str_contains((string) file_get_contents($file), '内核装配完成'), '内核 trace 未被日志系统消费');
});
