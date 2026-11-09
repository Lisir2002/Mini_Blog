<?php
declare(strict_types=1);

use Wx\Core\Http\Request;
use Wx\Core\Kernel;

/**
 * 18a · 版本升级与回退的完整链路。
 *
 * 用隔离站点（/tmp + 独立表前缀）真实执行一次「迁移 → 换单元 → 换 core → 健康检查」，
 * 再回退一次。断言以磁盘与数据库的事实为准，不看返回文案。
 * 请求与观测辅助见 tests/fixtures-release.php。
 */

check('版本升级：迁移、单元与 core 同步前进，旧版本留快照', function (): void {
    $dir = tempSite('rel');
    $prefix = 'rt18a_';
    dropTables($prefix);
    $GLOBALS['wx_rel_fixture'] = ['dir' => $dir, 'prefix' => $prefix];
    try {
        $k = isolatedKernel($dir, $prefix);
        $k->boot();
        $from = relVersion($dir);
        $pkg = fakeRelease($dir, '9.9.0');
        writeMigration(
            $pkg, '900_probe_18a',
            'CREATE TABLE IF NOT EXISTS wx_probe_18a (id INT PRIMARY KEY, note VARCHAR(40) NOT NULL)',
            'DROP TABLE IF EXISTS wx_probe_18a'
        );

        $plan = relPost($k, '/api/v1/release/plan', ['source' => $pkg]);
        truthy(($plan['body']['code'] ?? null) === 0, '预检失败：' . json_encode($plan['body'], JSON_UNESCAPED_UNICODE));
        truthy(in_array('release/900_probe_18a.sql', $plan['body']['data']['migrations']['pending'] ?? [], true),
            '预检未列出待执行迁移');
        truthy(($plan['body']['data']['units'] ?? []) !== [], '预检未识别出版本变化的系统层');
        truthy(($plan['body']['data']['reversible'] ?? false) === true, '有 down 脚本却报告不可逆');

        $up = relPost($k, '/api/v1/release/upgrade', ['source' => $pkg]);
        truthy(($up['body']['code'] ?? null) === 0, '升级失败：' . json_encode($up['body'], JSON_UNESCAPED_UNICODE));
        truthy(relVersion($dir) === '9.9.0', '升级后 core 版本未变化');
        truthy(in_array($prefix . 'probe_18a', relTables($k, $prefix), true), '迁移未真正建表');
        truthy(($up['body']['data']['health']['ok'] ?? false) === true, '升级后健康检查未通过');

        $status = $k->handle(new Request('GET', '/api/v1/release/status', [], adminHeaders()));
        $data = (array) json_decode($status->body(), true)['data'];
        truthy(($data['version'] ?? '') === '9.9.0', '状态接口版本未更新');
        truthy(($data['schema']['applied'] ?? 0) > 0, '迁移台账为空');
        $versions = array_column((array) ($data['releases'] ?? []), 'version');
        truthy(in_array($from, $versions, true), '升级前的版本没有留下快照');
        // 升级后「当前版本」通常还没有快照（快照是升级前的旧版）；标记字段必须存在且为布尔。
        foreach ((array) $data['releases'] as $release) {
            truthy(is_bool($release['current'] ?? null), '快照缺少 current 标记');
        }

        $history = $k->handle(new Request('GET', '/api/v1/release/history', ['limit' => 5], adminHeaders()));
        $rows = (array) json_decode($history->body(), true)['data']['history'];
        truthy(($rows[0]['action'] ?? '') === 'upgrade' && ($rows[0]['result'] ?? '') === 'ok', '升级历史未记录');
        truthy(in_array('release/900_probe_18a.sql', (array) ($rows[0]['migrations'] ?? []), true), '历史未记录本次迁移');
    } catch (Throwable $e) {
        dropTables($prefix);
        \Wx\Systems\Lifecycle\Fs::removeDir($dir);
        throw $e;
    }
});

check('版本回退：撤销迁移、回退单元与 core，并再次留快照', function (): void {
    $fixture = $GLOBALS['wx_rel_fixture'] ?? null;
    truthy($fixture !== null, '前置升级用例未完成');
    $dir = (string) $fixture['dir'];
    $prefix = (string) $fixture['prefix'];
    try {
        $k = isolatedKernel($dir, $prefix);
        $k->boot();
        $rb = relPost($k, '/api/v1/release/rollback', ['version' => '0.11.0']);
        truthy(($rb['body']['code'] ?? null) === 0, '回退失败：' . json_encode($rb['body'], JSON_UNESCAPED_UNICODE));
        truthy(relVersion($dir) === '0.11.0', '回退后 core 版本未恢复');
        truthy(!in_array($prefix . 'probe_18a', relTables($k, $prefix), true), '回退后迁移建的表仍然存在');
        truthy(in_array('release/900_probe_18a.sql', (array) ($rb['body']['data']['reverted'] ?? []), true), '回退未撤销迁移');

        $sys = json_decode((string) file_get_contents($dir . '/systems/Release/system.json'), true);
        truthy(version_compare((string) $sys['version'], '999.0.0', '<'), '回退后系统层版本未恢复');
    } finally {
        dropTables($prefix);
        \Wx\Systems\Lifecycle\Fs::removeDir($dir);
    }
});
