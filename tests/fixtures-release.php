<?php
declare(strict_types=1);

/**
 * 版本系统夹具 —— 只服务版本升级 / 回退相关守卫（18a、18b）。
 *
 * 与 fixtures.php 分开：那边是「造站点、清表、造包」的站点夹具，
 * 这边是「发请求、读版本、数表」的版本观测面。混在一起会让一个文件同时
 * 承担建站与版本观测两种职权（规矩 H）。
 * 本文件由 tests/run.php 在 helpers.php / fixtures.php 之后加载。
 */

use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Systems\Release\ReleaseManifest;

/** @param array<string, mixed> $body */
function relPost(Kernel $k, string $path, array $body): array
{
    $r = $k->handle(new Request('POST', $path, [], adminHeaders(), [], (string) json_encode($body)));
    return ['status' => $r->status(), 'body' => (array) json_decode($r->body(), true)];
}

/** 前缀下的实际表名（断言以库里的事实为准，不看返回文案）。 */
function relTables(Kernel $k, string $prefix): array
{
    $db = $k->container()->resolve(\Wx\Contracts\DatabaseInterface::class);
    $rows = $db->query('SHOW TABLES');
    return array_values(array_filter(
        array_map(static fn(array $row): string => (string) reset($row), $rows),
        static fn(string $name): bool => str_starts_with($name, $prefix)
    ));
}

/** 直接读磁盘上的版本清单：确认「文件真的换了」，而不是只有台账变了。 */
function relVersion(string $dir): string
{
    return ReleaseManifest::version(ReleaseManifest::read($dir . '/core'));
}

/** 某次操作后 core 版本与当前 site 的版本是否一致（防止「台账成功、文件未换」）。 */
function relSiteVersion(Kernel $k): string
{
    $status = $k->handle(new Request('GET', '/api/v1/release/status', [], adminHeaders()));
    $data = (array) json_decode($status->body(), true)['data'];
    return (string) ($data['version'] ?? '');
}
