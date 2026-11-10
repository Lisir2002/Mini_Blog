<?php
declare(strict_types=1);
namespace Wx\Tools\Facts;

/**
 * 文档 / 守卫计数对账 —— 把「每次升级靠记忆同步十几个文件」变成一条可重跑命令。
 *
 * 边界（刻意收窄，避免误伤）：
 *   1. 只锁「当前基线陈述」的精确句子，历史版本表里的 `240 passed`、`第 11 个系统层`
 *      是史实，不在锚点表内；
 *   2. 结构性事实逐一对账；运行快照（文件数 / 有效行）只与审计报告核对；
 *   3. 守卫里的硬编码锁保持独立（不读事实源放宽自己），由本工具反向核对「锁没过期」。
 *
 * 同一模式用 preg_match_all 全量比对：文档里 `67` 与 `69` 并存也会一并报出。
 */
final class Reconciler
{
    /**
     * @return array<string, array<string, list<string>>> 文件 => 正则 => 各捕获组对应的事实键
     *
     * public 是为了让 Coverage（反向锚点）复用同一张表：两套锚点定义必然漂移，
     * 反向检测若拿不到「什么算已纳管」，只会把已锚定的数字再报一遍。
     */
    public static function anchors(): array
    {
        $op = ['operations'];
        return [
            'README.md' => [
                '/(\d+) 个系统层各自声明/' => ['system_layers'],
                '/(\d+) 个接口由 `api\/registry\.json` 单向生成/' => $op,
                '/它固定 (\d+) 个 PHP 文件/' => ['core_files'],
                '/接口清单驱动 \| (\d+) 个接口 = (\d+) 份文档 = (\d+) 个 operation/' =>
                    ['operations', 'operations', 'operations'],
                '/可插拔系统层 \| (\d+) 层覆盖/' => ['system_layers'],
                '/\| L1 系统层 \| (\d+) 层/' => ['system_layers'],
                '/(\d+) 个系统层，各带 system\.json/' => ['system_layers'],
                '/当前基线：[^\n]*?(\d+) 系统层、(\d+) 接口/' => ['system_layers', 'operations'],
                '/`core\/` (\d+) 文件/' => ['core_files'],
                '/\| (\d+) 个接口索引 \|/' => $op,
                '/OpenAPI 3\.1（(\d+) 路径 \/ (\d+) 操作）/' => ['api_paths', 'operations'],
                '/version-(\d+\.\d+\.\d+)-/' => ['version'],
                '/registry\.json\s+接口清单（唯一事实源，(\d+) 条）/' => $op,
                '/专题与 (\d+) 份接口文档/' => $op,
                '/tests\/\s+守卫分组（见 tests\/groups\/）/' => ['literal_ok'],
                // 徽章同样不得写死通过数：它每次运行都变，写死必过期（v0.20 实测徽章 282 / 实际 309，
                // 而 282 不在任何锚点里，于是对账报「零漂移」——这正是v0.20 要消灭的「安静地错」）。
                '/badge\/tests-all%20passing/' => ['literal_ok'],
                '/重新生成 (\d+) 份接口文档/' => $op,
                // 通过数是运行输出，静态工具拿不到真值，文档改为「全绿」定性表述；
                // 这里只守「不再写死一个会过期的数字」。
                '/当前基线：php tests\/run\.php 全绿/' => ['literal_ok'],
            ],
            'docs/DEVELOPMENT.md' => [
                '/重新生成 (\d+) 份接口文档/' => $op,
                '/全量守卫（当前数字见 `php tools\/facts\.php`）/' => ['literal_ok'],
                '/含 (\d+) 份接口文档/' => $op,
            ],
            'docs/api/SPEC.md' => [
                '/（(\d+) 条路径 \/ (\d+) 个 operation）/' => ['api_paths', 'operations'],
            ],
            'docs/api/README.md' => [
                '/共 (\d+) 个接口，接口版本/' => $op,
            ],
            'docs/ARCHITECTURE.md' => [
                '/\| `SystemInterface` \| (\d+) 个系统层 \|/' => ['system_layers'],
                '/`core\/` 目前 \*\*(\d+) 个 PHP 文件\*\*（其中 (\d+) 个契约接口）/' =>
                    ['core_files', 'contracts_files'],
                '/`api\/registry\.json` 定义 \*\*(\d+) 个接口\*\*/' => $op,
                '/（(\d+) 条路径 \/ (\d+) 个 operation，同路径多方法合并）/' =>
                    ['api_paths', 'operations'],
                '/`php -l` 全量 \*\*(\d+) 个 PHP 文件\*\*/' => ['php_files'],
                '/其中 `core\/` 仅 \*\*(\d+) 个\*\*/' => ['core_files'],
                '/\*\*(\d+) 个系统层\*\*自动装配/' => ['system_layers'],
                '/(\d+) 个系统层，每目录/' => ['system_layers'],
                '/（(\d+) 系统层 \+ 4 主题/' => ['system_layers'],
                '/\*\*(\d+) 个契约接口文件\*\*/' => ['contracts_files'],
                '/\*\*(\d+) 个接口 \/ (\d+) 份文档\*\*/' => ['operations', 'operations'],
                '/OpenAPI 3\.1（(\d+) 路径 \/ (\d+) 操作）/' => ['api_paths', 'operations'],
                '/\*\*(\d+) 个权限点\*\*/' => ['permissions'],
                '/(\d+) 个文件共 ([\d,]+) 有效代码行/' => ['php_files', 'total_sloc'],
                '/(\d+) 个 operation 全部绑定执行者/' => $op,
                // 项目结构树里的三条：曾长期写着 67（v0.18 已是 69），只因不在锚点里而无人发现。
                '/接口清单（唯一事实源，(\d+) 条）/' => $op,
                '/SPEC\.md \+ (\d+) 份 API-\*\.md/' => $op,
                '/生成 (\d+) 份文档与索引/' => $op,
                // 历史陈述「v0.14–v0.19 未碰 core/」不带版本尾数，避免被误当当前版本对账。
                '/v0\.14 – v0\.19 连 `core\/` 一个文件都没碰/' => ['literal_ok'],
            ],
            'docs/CONVENTIONS.md' => [
                '/生成 (\d+) 份文档 \+ 索引/' => $op,
                '/（(\d+) 路径 \/ (\d+) 操作）/' => ['api_paths', 'operations'],
                '/（(\d+) 有效代码行/' => ['total_sloc'],
                '/(\d+) 个 operation 全绑定执行者/' => $op,
                '/`php -l` 全量 \*\*(\d+)\*\* 个 PHP 文件/' => ['php_files'],
                '/`core\/` 仅 (\d+) 个/' => ['core_files'],
                '/\*\*当前运行记录\*\*（v0\.(\d+)）/' => ['version_minor'],
            ],
            'docs/architecture.html' => [
                '/(\d+) 个 PHP 文件，其中 (\d+) 个契约接口/' => ['core_files', 'contracts_files'],
                '/(\d+) 个接口，一个 operation 一个执行者/' => $op,
                '/(\d+) 个接口，逐条声明路径/' => $op,
                // 只锁当前基线那一张卡片；历史版本里的「新增 5 个 / 3 个权限点」是史实，不纳入。
                '/留痕。(\d+) 个权限点、14 个权限接口/' => ['permissions'],
                '/(\d+) 个系统层 \/ 全部承载包/' => ['system_layers'],
                '/(\d+) 个系统层自动装配、13 个契约/' => ['system_layers'],
                '/(\d+) 个接口对应 (\d+) 份文档/' => ['operations', 'operations'],
                '/OpenAPI 3\.1（(\d+) 路径 \/ (\d+) 操作）/' => ['api_paths', 'operations'],
                '/v0\.(\d+)\.0、PHP/' => ['version_minor'],
                // 页眉三处此前从未纳入锚点，长期停在 v0.15 时代的「14 系统层 · 55 接口」。
                // 手写页面的副本比 Markdown 更容易腐坏：改动时不会有人同时想起它。
                '/Architecture Overview · v0\.(\d+)/' => ['version_minor'],
                '/建议全数采纳 · (\d+) 系统层 · (\d+) 接口/' => ['system_layers', 'operations'],
                '/全量 <strong>(\d+)<\/strong> 个 PHP 文件通过/' => ['php_files'],
            ],
            'docs/METRICS.md' => [
                '/内置 \*\*(\d+)\*\* 个指标/' => ['metrics'],
            ],
            'docs/PERFORMANCE.md' => [
                // 性能数字随机器负载波动，不逐项锚定；只锁两件事：
                // 代价数据必须指向可重跑命令，通过数不得写死（这里曾以 250/50 静默过期）。
                '/php tools\/bench\.php stability/' => ['literal_ok'],
                // 规模数据同属机器快照，不锚数值；只锁「结论必须指向可重跑命令」。
                '/php tools\/scale\.php run/' => ['literal_ok'],
                '/全量结果以 `php tests\/run\.php` 为准/' => ['literal_ok'],
            ],
            'api/registry.json' => [
                '/"api_operations": (\d+),/' => $op,
                '/"core_version": "(\d+\.\d+\.\d+)",/' => ['version'],
            ],
            'core/version.json' => [
                '/"version": "(\d+\.\d+\.\d+)"/' => ['version'],
            ],
        ];
    }

    /**
     * @return array{ok:bool, checked:int, findings:list<string>}
     */
    public static function run(string $root, array $facts): array
    {
        $facts['version_minor'] = (int) explode('.', (string) $facts['version'])[1];
        $findings = [];
        $checked = 0;

        foreach (self::anchors() as $rel => $patterns) {
            $src = (string) @file_get_contents($root . '/' . $rel);
            if ($src === '') {
                $findings[] = "{$rel}: 文件不存在或为空";
                continue;
            }
            foreach ($patterns as $pattern => $keys) {
                if (preg_match_all($pattern, $src, $m, PREG_SET_ORDER) === 0) {
                    $findings[] = "{$rel}: 锚点缺失 {$pattern}";
                    continue;
                }
                foreach ($m as $hit) {
                    foreach ($keys as $i => $key) {
                        // 定性锚点：命中即通过，用于「通过数改为引用命令」这类无静态真值的表述。
                        if ($key === 'literal_ok') { $checked++; continue; }
                        $raw = str_replace(',', '', (string) ($hit[$i + 1] ?? ''));
                        $expected = (string) ($facts[$key] ?? '?');
                        $checked++;
                        if ($raw !== $expected) {
                            $excerpt = substr($hit[0], 0, 60);
                            $findings[] = "{$rel}: {$key} 写的是 {$raw}，事实是 {$expected}"
                                . '（命中「' . $excerpt . '」）';
                        }
                    }
                }
            }
        }
        return ['ok' => $findings === [], 'checked' => $checked, 'findings' => $findings];
    }

    /**
     * 反向核对守卫锁：硬编码的 `=== N` 必须与事实一致。
     * 锁本身仍留在守卫里保持独立性，这里只负责报「锁过期了」。
     * @return list<string>
     */
    public static function guardLocks(string $root, array $facts): array
    {
        $locks = [
            'tests/groups/06-api-docs.php' => [
                '/count\(\$registry->endpoints\(\)\) === (\d+)/' => 'operations',
                '/api_operations.. \?\? 0\) === (\d+)/' => 'operations',
            ],
            'tests/groups/13a-permission-model.php' => [
                '/count\(\$reg->all\(\)\) === (\d+)/' => 'permissions',
            ],
            'tests/groups/13d-permission-cache-field.php' => [
                '/count\(\$perms->all\(\)\) === (\d+)/' => 'permissions',
            ],
            'tests/groups/14c-contract-override.php' => [
                '/count\(\$d\[.endpoints.\]\) === (\d+)/' => 'operations',
            ],
        ];
        $out = [];
        foreach ($locks as $rel => $patterns) {
            $src = (string) @file_get_contents($root . '/' . $rel);
            foreach ($patterns as $pattern => $key) {
                if (preg_match($pattern, $src, $m) !== 1) {
                    $out[] = "{$rel}: 锁模式缺失 {$pattern}";
                    continue;
                }
                if ((int) $m[1] !== (int) $facts[$key]) {
                    $out[] = "{$rel}: {$key} 锁写的是 {$m[1]}，事实是 {$facts[$key]}";
                }
            }
        }
        return $out;
    }
}
