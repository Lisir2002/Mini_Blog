<?php
declare(strict_types=1);
namespace Wx\Tools\Facts;

/**
 * 唯一事实集 —— 从仓库物理状态计算，不存结论、不引导内核、不连库。
 *
 * 为什么是「算」而不是「存」：任何写死的事实文件都会和它描述的对象一起漂移，
 * 等于又多一处要手工同步的地方。这里每一项都从清单 / 文件系统实时读出。
 *
 * 分两类：
 *   结构事实（current）   —— 接口数、权限点、契约、系统层、主题等，是可断言的不变量；
 *   运行快照（snapshot）  —— 文件数 / 有效行 / 守卫通过数，来自 tests/run.php 每次刷新
 *                             并提交的 docs/responsibility-report.json，本身自指，
 *                             只做展示与「文档 == 报告」核对，不当结构性锁。
 */
final class Facts
{
    /** @return array<string, int|string> */
    public static function compute(string $root): array
    {
        $reg = json_decode((string) @file_get_contents($root . '/api/registry.json'), true);
        $endpoints = (array) ($reg['endpoints'] ?? []);
        $paths = [];
        foreach ($endpoints as $e) $paths[(string) ($e['path'] ?? '')] = true;

        $perms = 0;
        foreach (glob($root . '/systems/*/permissions.json') ?: [] as $f) {
            $perms += count((array) (json_decode((string) file_get_contents($f), true)['permissions'] ?? []));
        }
        $metas = 0;
        foreach (glob($root . '/themes/*/theme.json') ?: [] as $f) {
            if (!empty(json_decode((string) file_get_contents($f), true)['meta'])) $metas++;
        }
        $oa = json_decode((string) @file_get_contents($root . '/docs/api/openapi.json'), true);
        $version = json_decode((string) @file_get_contents($root . '/core/version.json'), true);

        $report = json_decode((string) @file_get_contents($root . '/docs/responsibility-report.json'), true);
        $summary = (array) ($report['summary'] ?? []);

        return [
            'version'         => (string) ($version['version'] ?? 'unknown'),
            'operations'      => count($endpoints),
            'api_paths'       => count($paths),
            'openapi_paths'   => count((array) ($oa['paths'] ?? [])),
            'permissions'     => $perms,
            'contracts_files' => count(glob($root . '/core/Contracts/*.php') ?: []),
            'core_files'      => count(glob($root . '/core/*.php') ?: [])
                               + count(glob($root . '/core/*/*.php') ?: []),
            'system_layers'   => count(glob($root . '/systems/*/system.json') ?: []),
            'metrics'         => count(\Wx\Systems\Metric\MetricCatalog::builtin()),
            'themes'          => count(glob($root . '/themes/*/theme.json') ?: []),
            'meta_themes'     => $metas,
            'guard_groups'    => count(glob($root . '/tests/groups/*.php') ?: []),
            'php_files'       => (int) ($summary['php_files'] ?? 0),
            'total_sloc'      => (int) ($summary['total_sloc'] ?? 0),
        ];
    }

    /** @return list<string> 人类可读表格行 */
    public static function table(array $f): array
    {
        $labels = [
            'version' => '核心版本', 'operations' => '接口 operation', 'api_paths' => '接口去重路径',
            'openapi_paths' => 'OpenAPI 路径', 'permissions' => '权限点', 'contracts_files' => '契约接口文件',
            'core_files' => 'core/ PHP 文件', 'system_layers' => '系统层', 'themes' => '出厂主题',
            'meta_themes' => '元主题', 'metrics' => '内置指标', 'guard_groups' => '守卫分组',
            'php_files' => '全仓 PHP 文件（快照）', 'total_sloc' => '有效代码行（快照）',
        ];
        $out = [];
        foreach ($f as $k => $v) $out[] = sprintf('  %-24s %s', (string) ($labels[$k] ?? $k), (string) $v);
        return $out;
    }
}
