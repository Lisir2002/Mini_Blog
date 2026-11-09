<?php
declare(strict_types=1);

use Wx\Systems\Lifecycle\Fs;

/**
 * 18c · 安装窗口本身的安全边界。
 *
 * 只看三件事：向导是不是标准的五步、令牌是不是只在控制台可见、公网来源能不能动手。
 * 读写数据库的部分在 18d（步骤落地），本文件不碰库，避免一个文件同时承担两种职权。
 */

check('安装向导：五步结构齐备，令牌只在本机返回且权限 0600', function (): void {
    $dir = tempSite('inst');
    $prefix = 'rt18c_';
    dropTables($prefix);
    $GLOBALS['wx_inst_fixture'] = ['dir' => $dir, 'prefix' => $prefix];
    try {
        $k = isolatedKernel($dir, $prefix);
        $page = instGet($k);
        truthy($page['status'] === 200, '安装页未返回 200，实际 ' . $page['status']);
        truthy(($page['body']['installed'] ?? true) === false, '全新站点被判定为已安装');
        truthy(instSteps($page['body']) === ['preflight', 'database', 'admin', 'migrate', 'finish'],
            '五步顺序不符：' . implode('>', instSteps($page['body'])));
        truthy(($page['body']['next'] ?? '') === 'database', '全新站点应从 database 开始，实际 ' . ($page['body']['next'] ?? '-'));

        $token = instToken($page['body']);
        truthy(preg_match('/^[0-9a-f]{32}$/', $token) === 1, '令牌不是 32 位十六进制');
        $file = $dir . '/storage/install.token';
        truthy(is_file($file), '令牌文件未落盘');
        truthy((fileperms($file) & 0777) === 0600, '令牌文件权限不是 0600');
        truthy(trim((string) file_get_contents($file)) === $token, '页面令牌与磁盘令牌不一致');

        // 公网来源拿不到令牌块：安装窗口只在控制台里开。
        $remote = instGet($k, '203.0.113.9');
        truthy($remote['status'] === 403, '公网来源未拒绝，实际 ' . $remote['status']);
        truthy(($remote['body']['reason'] ?? '') === 'local_only', '拒绝原因不是 local_only');
        truthy(instToken($remote['body']) === '', '公网来源泄露了安装令牌');
    } catch (Throwable $e) {
        dropTables($prefix);
        Fs::removeDir($dir);
        throw $e;
    }
});

check('安装动作：缺失或错误令牌一律 403，且不写任何状态', function (): void {
    $fixture = $GLOBALS['wx_inst_fixture'] ?? null;
    truthy($fixture !== null, '前置安装页用例未完成');
    $dir = (string) $fixture['dir'];
    $k = isolatedKernel($dir, (string) $fixture['prefix']);

    $missing = instPost($k, ['step' => 'database']);
    truthy($missing['status'] === 403, '缺令牌未返回 403，实际 ' . $missing['status']);
    truthy(($missing['body']['code'] ?? 0) === 40301, '缺令牌错误码不是 40301');

    $wrong = instPost($k, ['step' => 'database', 'token' => str_repeat('0', 32)]);
    truthy($wrong['status'] === 403, '错令牌未返回 403，实际 ' . $wrong['status']);
    truthy(($wrong['body']['code'] ?? 0) === 40301, '错令牌错误码不是 40301');
    truthy(!is_file($dir . '/config/local.php'), '被拒绝的请求仍然写了 config/local.php');

    $unknown = instPost($k, ['step' => 'drop-everything', 'token' => instToken(instGet($k)['body'])]);
    truthy($unknown['status'] === 422, '未知步骤未返回 422，实际 ' . $unknown['status']);

    $remote = instPost($k, ['step' => 'finish'], '203.0.113.9');
    truthy($remote['status'] === 403, '公网写动作未拒绝');
    truthy(!is_file($dir . '/storage/install.lock'), '公网请求仍然完成了安装');
});
