<?php
declare(strict_types=1);

use Wx\Tools\Facts\CodeTable;

/**
 * 28 · 错误码表同步守卫 —— 文档承诺给调用方的码，必须一张不差地存在。
 *
 * 这一组守的不是「数字对不对」（那是 26b），而是「集合全不全」：
 * 少一个码不会让任何计数变红，但调用方会照着文档去分支一个不存在的 code，
 * 或收到一个文档里查不到的 code 而无从处理。v0.20 就漏了 5 个。
 *
 * 反向自检是刻意的：如果对账器永远返回 ok，这组守卫就只是自我安慰。
 */
echo "\n=== 28. 错误码表：Envelope::TABLE == SPEC.md §3 ===\n";

require_once $root . '/tools/facts/CodeTable.php';

check('文档与代码双向一一对应：不缺码、不多码、HTTP 状态一致', function () use ($root): void {
    $r = CodeTable::run($root);
    truthy($r['checked'] >= 15, '解析到的错误码过少，可能表格格式变了：' . $r['checked']);
    truthy($r['ok'], '错误码表漂移：' . implode(' | ', array_slice($r['findings'], 0, 6)));
});

check('反向自检：删掉一个码 / 改错状态时，对账器必须报出来', function () use ($root): void {
    $spec = (string) file_get_contents($root . '/docs/api/SPEC.md');
    // 只改内存副本，落盘文件的任何临时改动都可能被并发读取，这里绝不写盘。
    $broken = preg_replace('/^\| `50004` \|.*$/m', '', $spec, 1);
    truthy(is_string($broken) && $broken !== $spec, '自检前置失败：没能在副本里删掉 50004');
    truthy(preg_match('/^\| `50004` \|/m', $broken) === 0, '自检前置失败：50004 仍在副本里');

    // 临时目录只放一份被改坏的文档；Envelope 表由已加载的类提供（类不能重复定义）。
    $tmp = sys_get_temp_dir() . '/wx-spectest-' . getmypid();
    @mkdir($tmp . '/docs/api', 0777, true);
    file_put_contents($tmp . '/docs/api/SPEC.md', $broken);
    $r = CodeTable::run($tmp);
    @unlink($tmp . '/docs/api/SPEC.md');
    @rmdir($tmp . '/docs/api');
    @rmdir($tmp);

    truthy($r['ok'] === false, '删掉一个码后对账器仍报 ok —— 这组守卫没有意义');
    truthy(count($r['findings']) >= 1 && str_contains($r['findings'][0], '50004'), '报错信息没指出是 50004');
});

check('生成器与文档同源：接口文档 / OpenAPI 的状态与描述都取自 Envelope::TABLE', function () use ($root): void {
    foreach (['tools/gen-api-docs.php', 'tools/export-openapi.php'] as $f) {
        $src = (string) file_get_contents($root . '/' . $f);
        truthy(str_contains($src, 'Envelope::TABLE'), $f . ' 没有从 Envelope::TABLE 取错误码，会二次漂移');
    }
    $spec = (string) file_get_contents($root . '/docs/api/SPEC.md');
    truthy(str_contains($spec, '守卫 `28`'), 'SPEC 未声明本守卫，读者不知道这张表受约束');
});
