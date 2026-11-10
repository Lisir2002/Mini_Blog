<?php
declare(strict_types=1);
namespace Wx\Tools\Facts;

/**
 * 错误码表双向对账 —— `Envelope::TABLE` 与 `docs/api/SPEC.md` §3 必须一一对应。
 *
 * 为什么单独成类：Facts / Reconciler 管的是「数字」，这里管的是「集合」——
 * 少一个码不会让任何计数变红，调用方却会拿着文档去分支一个根本不存在的 code。
 * v0.20 就漏了 5 个（40903 / 40904 / 50002 / 50003 / 50004），文档却自称「避免多处漂移」。
 *
 * 边界：只比对码与 HTTP 状态。中文说明允许文档更口语（`无权限执行` vs `无权限执行该操作`），
 * 措辞不锁，避免为了一句文案反复改代码。
 */
final class CodeTable
{
    /** @return array{ok:bool, checked:int, findings:list<string>} */
    public static function run(string $root): array
    {
        // 类只加载一次：守卫会用临时目录喂一份被改坏的文档，此时复用已加载的表即可。
        if (!class_exists(\Wx\Systems\Api\Envelope::class, false)) {
            require_once $root . '/systems/Api/Envelope.php';
        }
        $table = \Wx\Systems\Api\Envelope::TABLE;

        $doc = (string) @file_get_contents($root . '/docs/api/SPEC.md');
        if ($doc === '') {
            return ['ok' => false, 'checked' => 0, 'findings' => ['docs/api/SPEC.md: 文件不存在或为空']];
        }
        if (preg_match('/## 3\. 统一错误码表(.*?)(?=\n## )/s', $doc, $sec) !== 1) {
            return ['ok' => false, 'checked' => 0, 'findings' => ['docs/api/SPEC.md: 找不到「## 3. 统一错误码表」章节']];
        }

        $preg = preg_match_all('/^\|\s*`(\d{5})`\s*\|\s*(\d{3})\s*\|/m', $sec[1], $rows, PREG_SET_ORDER);
        if ($preg === false || $preg === 0) {
            return ['ok' => false, 'checked' => 0, 'findings' => ['docs/api/SPEC.md: §3 表格里一个错误码都没解析到']];
        }

        $documented = [];
        foreach ($rows as $r) $documented[(int) $r[1]] = (int) $r[2];

        $findings = [];
        $checked = 0;
        foreach ($table as $code => [$http, $message]) {
            $checked++;
            if (!isset($documented[(int) $code])) {
                $findings[] = "{$code}（{$message}）在 Envelope::TABLE 里，SPEC.md §3 没有这一行";
                continue;
            }
            if ($documented[(int) $code] !== (int) $http) {
                $findings[] = "{$code} 的 HTTP 状态：文档写 {$documented[(int) $code]}，代码是 {$http}";
            }
        }
        foreach ($documented as $code => $http) {
            if (!isset($table[$code])) {
                $findings[] = "{$code} 在 SPEC.md §3 里，Envelope::TABLE 没有这个码";
            }
        }

        return ['ok' => $findings === [], 'checked' => $checked, 'findings' => $findings];
    }
}
