<?php
declare(strict_types=1);

use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Http\Request;

/**
 * 56 · 日志页运行时：只读、按天、窗口显式
 *
 * 从 HTTP 面证明五件事：
 *  1. 接口的 total 是文件行数而不是本次返回行数（这是本版修掉的缺陷，先钉住语义）；
 *  2. 页面把「没有这一天」「文件在但没行」「窗口内没该级别」「读不到」分成四种状态；
 *  3. 所有计数都限定在本次窗口内，文案不得把「窗口内没有」说成「这天没有」；
 *  4. 参数错一律 422，不静默回退成默认值；
 *  5. 只读：POST 被守卫挡回（Cookie 不参与写），带 Bearer 时才轮到 405。
 */
echo "\n=== 56. 日志页运行时 ===\n";

$site   = tempSite('logs-runtime');
$prefix = 'lg_';
dropTables($prefix);
$k = isolatedKernel($site, $prefix, [
    'theme' => ['active' => 'glass'],
    'permission' => ['cache' => ['driver' => 'memory']],
]);
$k->boot();

/** @return array{0:int, 1:string, 2:array<string,string>} */
$hit = static function (string $method, string $path, array $q = [], array $body = [],
    array $headers = []) use ($k): array {
    $r = $k->handle(new Request($method, $path, $q, $headers, $body));
    return [$r->status(), $r->body(), $r->headers()];
};
$registry = $k->container()->resolve(ApiRegistryInterface::class);

// 固定的测试日期，避免与真实日志撞车；30 行里 ERROR 落在 7/14/21/28。
$day = '2002-03-04';
$dir = $k->root() . '/storage/logs';
@mkdir($dir, 0775, true);
$lines = [];
for ($i = 1; $i <= 30; $i++) {
    $level = $i % 7 === 0 ? 'ERROR' : ($i % 5 === 0 ? 'WARN' : 'INFO');
    $lines[] = sprintf('[%s 10:%02d:%02d] %-7s 第 %d 条 {"n":%d}', $day, 0, $i % 60, $level, $i, $i);
}
file_put_contents($dir . '/' . $day . '.log', implode("\n", $lines) . "\n");
file_put_contents($dir . '/2002-03-05.log', '');

try {
    check('接口语义：total 是文件行数，returned 是本次窗口', function () use ($registry, $day): void {
        $data = $registry->execute('logs.query', ['date' => $day, 'limit' => 20]);
        truthy((int) ($data['total'] ?? -1) === 30, 'total 应为 30，实际 ' . ($data['total'] ?? 'null'));
        truthy((int) ($data['returned'] ?? -1) === 20, 'returned 应为 20，实际 ' . ($data['returned'] ?? 'null'));
        truthy(count((array) ($data['lines'] ?? [])) === 20, 'lines 应为 20 条');
        truthy(($data['truncated'] ?? false) === true, '30 行取 20 行应标记 truncated');
        truthy(($data['exists'] ?? false) === true, '文件存在时 exists 应为 true');
        truthy(in_array($day, (array) ($data['dates'] ?? []), true), 'dates 里缺少测试日期');
    });

    check('接口语义：不存在的日期 exists=false，空文件 exists=true 且 total=0', function () use ($registry): void {
        $none = $registry->execute('logs.query', ['date' => '1999-01-01']);
        truthy(($none['exists'] ?? true) === false, '缺失文件应 exists=false');
        truthy((int) ($none['total'] ?? -1) === 0, '缺失文件 total 应为 0');
        $empty = $registry->execute('logs.query', ['date' => '2002-03-05']);
        truthy(($empty['exists'] ?? false) === true, '空文件 exists 应为 true');
        truthy((int) ($empty['total'] ?? -1) === 0, '空文件 total 应为 0');
    });

    [$anon] = $hit('GET', '/console/logs');
    check('匿名访问：401', function () use ($anon): void {
        truthy($anon === 401, '匿名应 401，实际 ' . $anon);
    });

    [, , $h] = $hit('POST', '/login', [], ['username' => 'admin', 'password' => 'admin123']);
    $cookie = 'wx_session=' . cookieValue((string) ($h['Set-Cookie'] ?? ''), 'wx_session');
    [$status, $page] = $hit('GET', '/console/logs', ['date' => $day, 'limit' => '20'], [],
        ['Cookie' => $cookie]);

    check('管理员 GET：窗口说明与表格都在，且声明了截断', function () use ($status, $page): void {
        truthy($status === 200, '管理员应 200，实际 ' . $status);
        foreach (['共 30 行', '只取最近 20 行', '系统日志', '按级别筛选'] as $needle) {
            truthy(str_contains($page, $needle), '缺少文案：' . $needle);
        }
        truthy(substr_count($page, '<tr>') === 20, '表格应有 20 行，实际 ' . substr_count($page, '<tr>'));
        truthy(str_contains($page, 'data-stats-state="truncated"'), '应标记 truncated 状态');
    });

    check('日期切换来自接口 dates，不是页面自己扫目录', function () use ($page, $day): void {
        truthy(str_contains($page, 'href="/console/logs?date=' . $day), '日期列表缺少测试日期链接');
        truthy(str_contains($page, '2002-03-05'), '日期列表缺少空文件那一天');
    });

    check('级别筛选只筛窗口：ERROR 命中 3 行', function () use ($hit, $cookie, $day): void {
        [$s, $b] = $hit('GET', '/console/logs',
            ['date' => $day, 'limit' => '20', 'level' => 'error'], [], ['Cookie' => $cookie]);
        truthy($s === 200, '应 200，实际 ' . $s);
        truthy(substr_count($b, '<tr>') === 3, '窗口内应有 3 条 ERROR，实际 ' . substr_count($b, '<tr>'));
        truthy(str_contains($b, 'ERROR · 3 行'), '表头应写明 3 行');
    });

    check('窗口内没命中时，文案不得说成「这一天没有」', function () use ($hit, $cookie, $day): void {
        [, $b] = $hit('GET', '/console/logs',
            ['date' => $day, 'limit' => '20', 'level' => 'debug'], [], ['Cookie' => $cookie]);
        truthy(str_contains($b, 'data-stats-state="filtered-empty"'), '应标记 filtered-empty');
        truthy(str_contains($b, '本次窗口里没有 DEBUG'), '应说明是窗口内没有');
        truthy(str_contains($b, '共 30 行'), '应给出文件总行数，说明还有更早的没看');
        truthy(!str_contains($b, '这一天没有日志文件'), '不能把「窗口内没有」说成「这天没日志」');
    });

    check('没有这一天 / 文件在但没行：两种状态分开', function () use ($hit, $cookie): void {
        [, $missing] = $hit('GET', '/console/logs', ['date' => '1999-01-01'], [], ['Cookie' => $cookie]);
        truthy(str_contains($missing, 'data-stats-state="missing"'), '缺失文件应标记 missing');
        truthy(str_contains($missing, '没有日志文件'), '应明说没有日志文件');
        [, $empty] = $hit('GET', '/console/logs', ['date' => '2002-03-05'], [], ['Cookie' => $cookie]);
        truthy(str_contains($empty, 'data-stats-state="empty"'), '空文件应标记 empty');
        truthy(str_contains($empty, '一行也没有'), '应说明文件存在但没内容');
    });

    check('参数错一律 422：日期格式 / 不存在的日期 / 条数 / 级别', function () use ($hit, $cookie, $day): void {
        foreach ([
            ['date' => '2002/03/04'],
            ['date' => '2002-13-01'],
            ['date' => $day, 'limit' => '77'],
            ['date' => $day, 'level' => 'trace'],
        ] as $case) {
            [$s, $b] = $hit('GET', '/console/logs', $case, [], ['Cookie' => $cookie]);
            truthy($s === 422, json_encode($case) . ' 应 422，实际 ' . $s);
            truthy(str_contains($b, '查询条件不合法'), json_encode($case) . ' 缺少失败说明');
        }
    });

    check('原始视图：raw=1 输出未改写的内容', function () use ($hit, $cookie, $day): void {
        [$s, $b] = $hit('GET', '/console/logs',
            ['date' => $day, 'limit' => '20', 'raw' => '1'], [], ['Cookie' => $cookie]);
        truthy($s === 200, '应 200，实际 ' . $s);
        truthy(str_contains($b, 'id="log-raw"'), '缺少原始行区块');
        truthy(str_contains($b, '第 30 条'), '原始行应包含最后一行内容');
    });

    check('只读：Cookie 不参与写（401），Bearer 才轮到 405', function () use ($hit, $cookie): void {
        [$cookiePost] = $hit('POST', '/console/logs', [], ['x' => 1], ['Cookie' => $cookie]);
        truthy($cookiePost === 401, '带 Cookie 的写请求应 401，实际 ' . $cookiePost);
        [$s, $b, $h2] = $hit('POST', '/console/logs', [], ['x' => 1],
            ['Authorization' => 'Bearer test-admin-token']);
        truthy($s === 405, '带 Bearer 的写请求应 405，实际 ' . $s);
        truthy(($h2['Allow'] ?? '') === 'GET, HEAD', '405 应带 Allow 头');
        truthy(str_contains($b, '不接受提交'), '405 应说明只读');
    });
} finally {
    @unlink($dir . '/' . $day . '.log');
    @unlink($dir . '/2002-03-05.log');
    dropTables($prefix);
}
