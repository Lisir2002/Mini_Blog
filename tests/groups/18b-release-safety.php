<?php
declare(strict_types=1);

use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Http\Request;
use Wx\Systems\Lifecycle\Fs;

/**
 * 18b · 版本系统的三条安全边界。
 *
 * 升级/回退的正常链路已在 18a 走通；本文件只验证「什么时候必须拒绝」：
 *  1. 已执行的迁移脚本被改动 → 校验和冲突，拒绝升级（历史不可改写）；
 *  2. 缺 down 脚本 → 拒绝回退，且不做「代码退了库没退」的半截动作；
 *  3. 没有数据库 → 版本能力降级但不撒谎：查询可用、写入明确报错。
 * 另外验证「回退到指定旧版本」与「快照只保留 N 份」这两条运维承诺。
 */

/** 造一个带一条迁移的新版本包，返回包路径。 */
function relPkg(string $dir, string $version, string $name, string $up, ?string $down): string
{
    $pkg = fakeRelease($dir, $version);
    writeMigration($pkg, $name, $up, $down);
    return $pkg;
}

check('校验和冲突：已执行脚本被改动后拒绝升级，版本停在原地', function (): void {
    $dir = tempSite('relcs');
    $prefix = 'rt18b_';
    dropTables($prefix);
    try {
        $k = isolatedKernel($dir, $prefix);
        $k->boot();
        $up = 'CREATE TABLE IF NOT EXISTS wx_probe_cs (id INT PRIMARY KEY)';
        $down = 'DROP TABLE IF EXISTS wx_probe_cs';
        $first = relPkg($dir, '9.9.0', '900_probe_cs', $up, $down);
        $ok = relPost($k, '/api/v1/release/upgrade', ['source' => $first]);
        truthy(($ok['body']['code'] ?? null) === 0, '基线升级失败：' . json_encode($ok['body'], JSON_UNESCAPED_UNICODE));

        // 站长手改了一条已经执行过的脚本 —— 这正是 Flyway 会拦下的情形。
        $site = $dir . '/systems/Release/migrations/900_probe_cs.sql';
        file_put_contents($site, $up . "\n-- 手工补了一句\n");
        $second = relPkg($dir, '9.9.1', '900_probe_cs', $up, $down);
        $before = relVersion($dir);

        $plan = relPost($k, '/api/v1/release/plan', ['source' => $second]);
        $conflicts = (array) ($plan['body']['data']['conflicts'] ?? []);
        truthy($conflicts !== [], '预检没有报告校验和冲突');
        truthy(($conflicts[0]['id'] ?? '') === 'release/900_probe_cs.sql', '冲突项不是被改动的那条脚本');

        $res = relPost($k, '/api/v1/release/upgrade', ['source' => $second]);
        truthy($res['status'] === 409, '校验和冲突未返回 409，实际 ' . $res['status']);
        truthy(($res['body']['code'] ?? 0) === 40904, '冲突错误码不是 40904');
        truthy(relVersion($dir) === $before, '被拒绝的升级仍然换了 core 文件');
    } finally {
        dropTables($prefix);
        Fs::removeDir($dir);
    }
});

check('不可逆迁移：拒绝回退且不留半截状态，补齐 down 后可正常回退', function (): void {
    $dir = tempSite('relir');
    $prefix = 'rt18b2_';
    dropTables($prefix);
    try {
        $k = isolatedKernel($dir, $prefix);
        $k->boot();
        $up = 'CREATE TABLE IF NOT EXISTS wx_probe_ir (id INT PRIMARY KEY)';
        $pkg = relPkg($dir, '9.9.0', '910_probe_ir', $up, null);
        $ok = relPost($k, '/api/v1/release/upgrade', ['source' => $pkg]);
        truthy(($ok['body']['code'] ?? null) === 0, '升级失败：' . json_encode($ok['body'], JSON_UNESCAPED_UNICODE));
        truthy(in_array($prefix . 'probe_ir', relTables($k, $prefix), true), '迁移未建表');

        $blocked = relPost($k, '/api/v1/release/rollback', ['version' => '0.11.0']);
        truthy($blocked['status'] === 409, '缺 down 脚本未返回 409，实际 ' . $blocked['status']);
        truthy(($blocked['body']['code'] ?? 0) === 40903, '不可逆错误码不是 40903');
        truthy(relVersion($dir) === '9.9.0', '被拒绝的回退仍然换了 core 文件');
        truthy(in_array($prefix . 'probe_ir', relTables($k, $prefix), true), '被拒绝的回退仍然删了表');
        truthy(($ok['body']['data']['version'] ?? '') === '9.9.0', '升级后版本记录不一致');

        // 运维补上 down 脚本后，同一条回退应当立刻可用 —— 拒绝不等于损坏。
        file_put_contents($dir . '/systems/Release/migrations/910_probe_ir.down.sql', 'DROP TABLE IF EXISTS wx_probe_ir');
        $retry = relPost($k, '/api/v1/release/rollback', ['version' => '0.11.0']);
        truthy(($retry['body']['code'] ?? null) === 0, '补齐 down 后回退失败：' . json_encode($retry['body'], JSON_UNESCAPED_UNICODE));
        truthy(relVersion($dir) === '0.11.0', '补齐 down 后 core 未回退');
        truthy(!in_array($prefix . 'probe_ir', relTables($k, $prefix), true), '补齐 down 后表仍在');
    } finally {
        dropTables($prefix);
        Fs::removeDir($dir);
    }
});

check('多份快照：可回退到指定旧版本，keep_releases 只裁最旧的', function (): void {
    $dir = tempSite('relsnp');
    $prefix = 'rt18b3_';
    dropTables($prefix);
    try {
        $k = isolatedKernel($dir, $prefix);
        $k->boot();
        foreach (['9.9.0', '9.9.1'] as $v) {
            $res = relPost($k, '/api/v1/release/upgrade', ['source' => fakeRelease($dir, $v)]);
            truthy(($res['body']['code'] ?? null) === 0, '升级到 ' . $v . ' 失败：' . json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        }
        $status = (array) json_decode($k->handle(new Request('GET', '/api/v1/release/status', [], adminHeaders()))->body(), true)['data'];
        $versions = array_column((array) ($status['releases'] ?? []), 'version');
        truthy(count($versions) >= 2, '两次升级应留下至少两份快照，实际 ' . count($versions));
        truthy(in_array('9.9.0', $versions, true), '缺少上一个版本的快照');

        // 装卸系统的通用版本列表与版本系统看到的是同一批快照。
        $list = $k->handle(new Request('GET', '/api/v1/lifecycle/releases', ['type' => 'core', 'slug' => 'core'], adminHeaders()));
        $lh = (array) json_decode($list->body(), true);
        truthy(($lh['code'] ?? 0) === 0, '版本列表接口失败：' . json_encode($lh, JSON_UNESCAPED_UNICODE));
        truthy(count((array) ($lh['data']['releases'] ?? [])) === count($versions), '两个入口看到的快照数量不一致');

        $rb = relPost($k, '/api/v1/release/rollback', ['version' => '9.9.0']);
        truthy(($rb['body']['code'] ?? null) === 0, '回退到指定旧版本失败：' . json_encode($rb['body'], JSON_UNESCAPED_UNICODE));
        truthy(relVersion($dir) === '9.9.0', '未回退到指定版本，实际 ' . relVersion($dir));

        // keep_releases=2：再升一版，最旧的快照被裁掉，当前版本永远不裁。
        $k2 = isolatedKernel($dir, $prefix, ['lifecycle' => ['keep_releases' => 2]]);
        $k2->boot();
        $again = relPost($k2, '/api/v1/release/upgrade', ['source' => fakeRelease($dir, '9.9.2')]);
        truthy(($again['body']['code'] ?? null) === 0, '裁剪场景升级失败：' . json_encode($again['body'], JSON_UNESCAPED_UNICODE));
        $after = (array) json_decode($k2->handle(new Request('GET', '/api/v1/release/status', [], adminHeaders()))->body(), true)['data'];
        $kept = array_column((array) ($after['releases'] ?? []), 'version');
        truthy(count($kept) === 2, 'keep_releases=2 时快照数为 ' . count($kept));
        truthy(in_array('9.9.1', $kept, true), '裁剪把最近一份旧版本也删了');
        truthy(!in_array('0.11.0', $kept, true), '最旧的快照没有被裁掉');
    } finally {
        dropTables($prefix);
        Fs::removeDir($dir);
    }
});

check('无数据库：版本台账降级为内存，写操作明确拒绝而不是假成功', function (): void {
    $dir = tempSite('relnodb');
    try {
        $k = isolatedKernel($dir, 'rt18b4_', ['database' => ['dsn' => '']]);
        $k->boot();
        // 无库时权限层按设计 fail-closed，HTTP 入口会先被拦下；这里直接验版本系统自身的降级行为。
        $api = $k->container()->resolve(ApiRegistryInterface::class);
        $status = $api->execute('release.status', []);
        truthy((string) ($status['version'] ?? '') !== '', '无库时读不到版本清单');
        truthy(is_array($status['schema'] ?? null), '无库时状态结构不完整');

        $pkg = relPkg($dir, '9.9.0', '930_probe_nodb', 'CREATE TABLE IF NOT EXISTS wx_probe_nodb (id INT)', null);
        $code = 0;
        try {
            $api->execute('release.upgrade', ['source' => $pkg]);
        } catch (Throwable $e) {
            $code = (int) $e->getCode();
        }
        truthy($code === 50004, '无库升级未以 50004 拒绝，实际 ' . $code);
        truthy(relVersion($dir) === (string) $status['version'], '无库升级失败了却换了文件');
    } finally {
        dropTables('rt18b4_');
        Fs::removeDir($dir);
    }
});
