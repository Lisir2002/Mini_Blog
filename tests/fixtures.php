<?php
declare(strict_types=1);

/**
 * 隔离站点夹具 —— 只服务于「真的会改文件 / 改库」的守卫（版本升级、安装器）。
 *
 * 与 helpers.php 分开的原因：断言辅助只做断言，夹具要做文件复制与建库清理，
 * 混在一起会把一个文件同时拖进 filesystem、persistence、security 三个职权域（规矩 H）。
 * 本文件由 tests/run.php 在 helpers.php 之后加载。
 */

use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Systems\Lifecycle\Fs;
use Wx\Contracts\DatabaseInterface;

/**
 * 隔离站点夹具：把运行所需目录复制到 /tmp，供「真的会改文件」的守卫使用
 * （版本升级 / 安装器）。数据库用独立表前缀，绝不触碰工作区的 wx_ 表。
 */
function tempSite(string $tag): string
{
    global $root;
    $dir = sys_get_temp_dir() . '/wx-' . $tag . '-' . bin2hex(random_bytes(3));
    if (!is_dir($dir . '/storage')) mkdir($dir . '/storage', 0775, true);
    foreach (['core', 'systems', 'api', 'config', 'themes', 'pages', 'plugins'] as $part) {
        Fs::copyDir($root . '/' . $part, $dir . '/' . $part);
    }
    return $dir;
}

/** 隔离站点的内核：沿用主站 DSN，只换表前缀，并显式打开 core 升级与关闭限流。 */
function isolatedKernel(string $dir, string $prefix, array $extra = []): Kernel
{
    global $root;
    $cfg = require $root . '/config/app.php';
    $db = (array) ($cfg['database'] ?? []);
    return new Kernel($dir, array_replace_recursive([
        'theme' => ['active' => null],
        'database' => [
            'dsn' => (string) ($db['dsn'] ?? ''), 'user' => (string) ($db['user'] ?? ''),
            'pass' => (string) ($db['pass'] ?? ''), 'prefix' => $prefix,
        ],
        'api' => [
            'rate_limit' => ['enabled' => false],
            'tokens' => ['test-admin-token' => ['role' => 'admin', 'username' => 'admin', 'user_id' => 1]],
        ],
        // v0.64：生产默认已改为 fail-closed（require_checksum / require_signature = true）。
        // 本夹具用 fakeRelease 造的是「无清单无签名的站点副本」，走 release core 自升级，
        // 故在此显式声明降级档位：演练链路不假装走签名信任根，覆盖层优先级由此被验证。
        'lifecycle' => [
            'allow_core_update' => true,
            'require_checksum'  => false,
            'require_signature' => false,
            'signature_secret'  => '',
        ],
        'release'   => ['allow_core_update' => true],
    ], $extra));
}

/** 删除指定前缀的全部表（隔离站点清理）。 */
function dropTables(string $prefix): void
{
    global $root;
    $cfg = require $root . '/config/app.php';
    $db = (array) ($cfg['database'] ?? []);
    if ((string) ($db['dsn'] ?? '') === '') return;
    $pdo = new PDO((string) $db['dsn'], (string) $db['user'], (string) $db['pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        if (str_starts_with((string) $table, $prefix)) $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
    }
}

/** 在隔离站点里造一个「新版本包」：复制整棵树并抬高版本号。 */
function fakeRelease(string $dir, string $version, string $systemSlug = 'release', string $systemVersion = '999.0.0'): string
{
    $pkg = $dir . '/.packages/' . str_replace('.', '_', $version);
    if (is_dir($pkg)) Fs::removeDir($pkg);
    Fs::copyDir($dir . '/core', $pkg . '/core');
    Fs::copyDir($dir . '/systems', $pkg . '/systems');
    $file = $pkg . '/core/version.json';
    $data = json_decode((string) file_get_contents($file), true) ?: [];
    $data['version'] = $version;
    $data['released_at'] = date(DATE_ATOM);
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    // 目录名与清单 name 可能大小写不同（systems/Release 的 name 是 release），按 name 匹配。
    foreach (glob($pkg . '/systems/*/system.json') ?: [] as $sysFile) {
        $sys = json_decode((string) file_get_contents($sysFile), true) ?: [];
        $name = (string) ($sys['name'] ?? basename(dirname($sysFile)));
        if (strcasecmp($name, $systemSlug) !== 0) continue;
        $sys['version'] = $systemVersion;
        file_put_contents($sysFile, json_encode($sys, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    return $pkg;
}

/** 在隔离站点里写一条迁移（可选 down），返回 id。 */
function writeMigration(string $dir, string $name, string $up, ?string $down = null, string $owner = 'Release'): string
{
    $mig = $dir . '/systems/' . $owner . '/migrations';
    if (!is_dir($mig)) mkdir($mig, 0775, true);
    file_put_contents($mig . '/' . $name . '.sql', $up);
    if ($down !== null) file_put_contents($mig . '/' . $name . '.down.sql', $down);
    return strtolower($owner) . '/' . $name . '.sql';
}

/** @param array<string, mixed> $body 安装向导写动作；$ip 非本机时用于验证 local_only 拒绝 */
function instPost(Kernel $k, array $body, ?string $ip = null): array
{
    $r = $k->handle(new Request('POST', '/install', [], [], [], (string) json_encode($body), $ip));
    return ['status' => $r->status(), 'body' => (array) json_decode($r->body(), true)];
}

function instGet(Kernel $k, ?string $ip = null): array
{
    $r = $k->handle(new Request('GET', '/install', [], [], [], '', $ip));
    return ['status' => $r->status(), 'body' => (array) json_decode($r->body(), true)];
}

/** 从 GET /install 的 pre 块里取令牌（只有本机来源才会返回）。 */
function instToken(array $page): string
{
    foreach ((array) ($page['blocks'] ?? []) as $block) {
        if (($block['id'] ?? '') === 'install-token') return (string) ($block['value'] ?? '');
    }
    return '';
}

/** @return list<string> */
function instSteps(array $page): array
{
    return array_map(static fn(array $s): string => (string) $s['step'], (array) ($page['steps'] ?? []));
}

/** @return array<string, bool> 步骤 => 是否已完成 */
function instDone(array $page): array
{
    $out = [];
    foreach ((array) ($page['steps'] ?? []) as $s) $out[(string) $s['step']] = (bool) $s['done'];
    return $out;
}

/** 安装窗口内的一次完整数据库步骤参数（沿用主站 DSN，只换前缀）。 */
function instDatabase(): array
{
    global $root;
    $cfg = require $root . '/config/app.php';
    $db = (array) ($cfg['database'] ?? []);
    $dsn = (string) ($db['dsn'] ?? '');
    $out = ['host' => '127.0.0.1', 'port' => 3306, 'database' => '', 'user' => (string) ($db['user'] ?? ''), 'pass' => (string) ($db['pass'] ?? '')];
    foreach (explode(';', substr($dsn, (int) strpos($dsn, ':') + 1)) as $part) {
        $pair = explode('=', $part, 2);
        if (count($pair) !== 2) continue;
        $key = trim($pair[0]);
        if ($key === 'host') $out['host'] = $pair[1];
        if ($key === 'port') $out['port'] = (int) $pair[1];
        if ($key === 'dbname') $out['database'] = $pair[1];
    }
    return $out;
}
