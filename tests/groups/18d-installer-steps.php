<?php
declare(strict_types=1);

use Wx\Core\Kernel;
use Wx\Systems\Lifecycle\Fs;

/**
 * 18d · 安装步骤的落地效果：配置怎么写、账号怎么建、迁移怎么记账、最后怎么加锁。
 *
 * 断言只认磁盘与数据库的事实：local.php 的权限与内容、账号能否通过 password_verify、
 * 台账是否登记、install.lock 是否存在、向导是否真的 404。
 */
check('数据库步骤：只写 config/local.php，覆盖生效，删掉即回默认', function (): void {
    $fixture = $GLOBALS['wx_inst_fixture'] ?? null;
    truthy($fixture !== null, '前置安装用例未完成');
    $dir = (string) $fixture['dir'];
    $prefix = (string) $fixture['prefix'];
    $k = isolatedKernel($dir, $prefix);
    $token = instToken(instGet($k)['body']);
    $mainBefore = (string) md5_file($dir . '/config/app.php');

    $res = instPost($k, ['step' => 'database', 'token' => $token, 'prefix' => $prefix] + instDatabase());
    truthy($res['status'] === 200, '数据库步骤失败：' . json_encode($res['body'], JSON_UNESCAPED_UNICODE));
    truthy(($res['body']['result']['connected'] ?? false) === true, '未报告连接成功');

    $local = $dir . '/config/local.php';
    truthy(is_file($local), '未生成 config/local.php');
    truthy((fileperms($local) & 0777) === 0600, 'config/local.php 权限不是 0600');
    $data = require $local;
    truthy((string) ($data['database']['prefix'] ?? '') === $prefix, 'local.php 未记录表前缀');
    truthy((string) ($data['database']['user'] ?? '') !== '', 'local.php 未记录数据库用户');
    truthy((string) md5_file($dir . '/config/app.php') === $mainBefore, '带注释的主配置被覆盖了');

    // 覆盖层真的生效：新内核不传任何 database 覆盖，也能读到安装器写的这段。
    $fresh = new Kernel($dir, ['theme' => ['active' => null]]);
    truthy($fresh->config()->get('database.prefix') === $prefix, 'local.php 覆盖未生效');
    truthy($fresh->config()->get('database.dsn') === ($data['database']['dsn'] ?? null), 'DSN 覆盖未生效');

    // 删除即回默认：不需要「反安装」，也不留隐式状态。
    unlink($local);
    $back = new Kernel($dir, ['theme' => ['active' => null]]);
    truthy($back->config()->get('database.prefix') === 'wx_', '删除 local.php 后未回到主配置默认值');

    // 回退后再走一次同一步骤：安装未完成时随时可以重来，不需要清空任何东西。
    $redo = instPost($k, ['step' => 'database', 'token' => $token, 'prefix' => $prefix] + instDatabase());
    truthy($redo['status'] === 200, '删除 local.php 后无法重做数据库步骤');
    truthy(is_file($local), '重做数据库步骤后 local.php 仍不存在');
});

check('管理员账号：可登录、重复提交幂等、弱口令被拒', function (): void {
    $fixture = $GLOBALS['wx_inst_fixture'] ?? null;
    $dir = (string) $fixture['dir'];
    $prefix = (string) $fixture['prefix'];
    $k = isolatedKernel($dir, $prefix);
    $token = instToken(instGet($k)['body']);
    $db = $k->container()->resolve(\Wx\Contracts\DatabaseInterface::class);
    $sql = 'SELECT username,password_hash,role FROM ' . $prefix . 'user_accounts';

    $first = instPost($k, ['step' => 'admin', 'token' => $token, 'username' => 'webmaster', 'password' => 'install-pass-2026']);
    truthy($first['status'] === 200, '管理员步骤失败：' . json_encode($first['body'], JSON_UNESCAPED_UNICODE));
    $again = instPost($k, ['step' => 'admin', 'token' => $token, 'username' => 'webmaster', 'password' => 'install-pass-2026']);
    truthy($again['status'] === 200, '重复提交管理员失败');

    $mine = array_values(array_filter($db->query($sql), static fn(array $r): bool => $r['username'] === 'webmaster'));
    truthy(count($mine) === 1, '重复提交产生了多个同名账号，实际 ' . count($mine));
    truthy((string) $mine[0]['role'] === 'admin', '账号角色不是 admin');
    truthy(password_verify('install-pass-2026', (string) $mine[0]['password_hash']), '密码哈希不可验证');

    $weak = instPost($k, ['step' => 'admin', 'token' => $token, 'username' => 'x', 'password' => '123']);
    truthy($weak['status'] === 422, '弱口令 / 非法用户名未返回 422，实际 ' . $weak['status']);
});

check('迁移与加锁：台账登记、进度反映事实、装完向导 404', function (): void {
    $fixture = $GLOBALS['wx_inst_fixture'] ?? null;
    $dir = (string) $fixture['dir'];
    $prefix = (string) $fixture['prefix'];
    $k = isolatedKernel($dir, $prefix);
    $token = instToken(instGet($k)['body']);

    $mig = instPost($k, ['step' => 'migrate', 'token' => $token]);
    truthy($mig['status'] === 200, '迁移步骤失败：' . json_encode($mig['body'], JSON_UNESCAPED_UNICODE));
    truthy(($mig['body']['result']['applied'] ?? 0) > 0, '迁移台账为空');
    truthy(($mig['body']['result']['tables'] ?? 0) > 0, '安装后一张表都没有');

    $done = instDone(instGet($k)['body']);
    truthy($done['database'] === true && $done['admin'] === true && $done['migrate'] === true,
        '步骤进度未反映库内事实：' . json_encode($done));

    $fin = instPost($k, ['step' => 'finish', 'token' => $token]);
    truthy($fin['status'] === 200, '加锁失败：' . json_encode($fin['body'], JSON_UNESCAPED_UNICODE));
    truthy(is_file($dir . '/storage/install.lock'), 'install.lock 未落盘');
    truthy(!is_file($dir . '/storage/install.token'), '令牌在安装完成后仍然存在');
    $lock = (array) json_decode((string) file_get_contents($dir . '/storage/install.lock'), true);
    truthy(($lock['installed_at'] ?? '') !== '', '安装锁没有记录时间');

    truthy(instGet($k)['status'] === 404, '已安装后 GET /install 未 404');
    truthy(instPost($k, ['step' => 'finish', 'token' => $token])['status'] === 404, '已安装后 POST /install 未 404');

    dropTables($prefix);
    Fs::removeDir($dir);
});
