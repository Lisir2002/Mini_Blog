<?php
declare(strict_types=1);

use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Systems\Api\IdempotencyStore;
use Wx\Systems\Api\RateLimiter;
use Wx\Systems\Data\PdoDatabase;

echo "\n=== 27. 稳定性加固（故障注入） ===\n";

/**
 * 这一组的共同点：全部是「安静地错」的场景。
 * 正常路径断言只能证明功能存在，证明不了故障时会不会挂死 / 静默失效 / 悄悄返回错数据。
 */

check('数据库连接超时：不可达库在超时内失败，正常库不受影响', function () use ($root): void {
    $db = new PdoDatabase('mysql:host=192.0.2.1;port=3306;dbname=x', 'u', 'p', 'wx_', null, 1);
    $start = microtime(true);
    try { $db->query('SELECT 1'); } catch (Throwable) { /* 预期失败 */ }
    $elapsed = microtime(true) - $start;

    // 未设超时时依赖系统 socket 默认（本机为 30s+）；设 1s 必须显著更小。
    truthy($elapsed < 8.0, '不可达库未在超时内失败，耗时 ' . round($elapsed, 2) . 's');
    truthy((int) (new Kernel($root, []))->config()->get('database.timeout.connect', 0) === 2,
        '默认连接超时应为 2s');

    $ok = new PdoDatabase('mysql:host=127.0.0.1;dbname=wx_blog;charset=utf8mb4', 'wx', 'wx_pass_2026', 'wx_');
    truthy(($ok->query('SELECT 1 AS n')[0]['n'] ?? 0) === 1, '正常库连接被超时设置破坏');
});

check('健康检查分级：ok / down / degraded 三态可判，且不回显表名', function () use ($root): void {
    $decode = static function (array $cfg) use ($root): array {
        $r = (new Kernel($root, $cfg))->handle(new Request('GET', '/api/v1/system/health'));
        return (array) (json_decode($r->body(), true)['data'] ?? []);
    };

    $ok = $decode([]);
    truthy($ok['status'] === 'ok', '正常环境应为 ok，实际 ' . ($ok['status'] ?? '?'));
    truthy(isset($ok['checks']['database'], $ok['checks']['storage'], $ok['checks']['theme']),
        'checks 缺少 database / storage / theme');
    truthy(array_key_exists('table_count', $ok), '应改用 table_count');
    truthy(!array_key_exists('tables', $ok), 'public 健康接口不应回显全表名');

    $down = $decode(['database' => ['dsn' => '', 'auto_fallback' => true]]);
    truthy($down['status'] === 'down', '库不可用应为 down，实际 ' . ($down['status'] ?? '?'));

    $degraded = $decode(['theme' => ['active' => 'no-such-theme']]);
    truthy($degraded['status'] === 'degraded', '主题无法解析应为 degraded，实际 ' . ($degraded['status'] ?? '?'));
    truthy(($degraded['checks']['theme']['status'] ?? '') === 'degraded', 'checks.theme 未标记异常');
});

check('幂等占位：同键并发只有一个 claimed，其余 inflight；失败可重试', function (): void {
    $dir = sys_get_temp_dir() . '/wx-guard-idem-' . bin2hex(random_bytes(4));
    mkdir($dir, 0775, true);
    try {
        $store = new IdempotencyStore($dir, 3600);
        truthy($store->claim('k', 'fp')[ 'state'] === 'claimed', '首次应 claimed');
        truthy($store->claim('k', 'fp')['state'] === 'inflight', '同键并发应 inflight');
        truthy($store->claim('k', 'other')['state'] === 'conflict', '不同参数应 conflict');

        $store->complete('k', 'fp', 200, ['code' => 0]);
        $replay = $store->claim('k', 'fp');
        truthy($replay['state'] === 'replay' && $replay['status'] === 200, '回填后应可重放');

        $store->release('k');
        truthy($store->claim('k', 'fp')['state'] === 'claimed', '释放后应可重新占位');
    } finally {
        foreach (glob($dir . '/storage/api/idempotency/*') ?: [] as $f) @unlink($f);
        @rmdir($dir . '/storage/api/idempotency');
        @rmdir($dir . '/storage/api');
        @rmdir($dir . '/storage');
        @rmdir($dir);
    }
});

check('限流计数原子：连续请求不丢计数，读改写带排他锁', function () use ($root): void {
    $dir = sys_get_temp_dir() . '/wx-guard-rate-' . bin2hex(random_bytes(4));
    mkdir($dir, 0775, true);
    try {
        $limiter = new RateLimiter($dir, 100, 60);
        $last = null;
        for ($i = 0; $i < 40; $i++) $last = $limiter->check('ip');
        truthy($last['remaining'] === 60, '40 次请求后 remaining 应为 60，实际 ' . $last['remaining']);
        truthy($last['degraded'] === false, '正常路径不应降级');

        // 静态证据：读写必须在同一把锁内，否则并发会各自读旧值再写回。
        $src = (string) file_get_contents($root . '/systems/Api/RateLimiter.php');
        truthy(str_contains($src, 'flock($fh, LOCK_EX)'), '限流未使用排他锁');
        truthy(!str_contains($src, 'file_put_contents($file'), '仍存在无锁的整文件写入');
    } finally {
        @rmdir($dir . '/storage/api/ratelimit');
        @rmdir($dir . '/storage/api');
        @rmdir($dir . '/storage');
        @rmdir($dir);
    }
});

check('稳定性加固未新增系统层 / 接口 / 核心文件', function () use ($root): void {
    truthy(count(glob($root . '/core/*.php') ?: []) + count(glob($root . '/core/*/*.php') ?: []) === 24,
        'core/ 文件数被改动');
    truthy(count(glob($root . '/systems/*/system.json') ?: []) === 18, '系统层数量被改动');
    $registry = json_decode((string) file_get_contents($root . '/api/registry.json'), true);
    truthy(count($registry['endpoints'] ?? []) === 92, '接口清单被改动');
});
