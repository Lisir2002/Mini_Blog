<?php
declare(strict_types=1);
namespace Wx\Tools\Audit;

/**
 * 单文件职责预算（规矩 H）。
 *
 * 「职权过重」不靠主观判断，而是四个可静态复现的指标：
 *   sloc          有效代码行（剔除空行与整行注释）
 *   classes       责任主体数：一个文件只允许一个类 / 接口 / trait
 *   public_methods 对外承诺数：超过上限说明该 class 在替别人干活
 *   fan_out       扇出：use 语句数，反映协作面宽度
 *   concerns      业务职权跨度：同时沾手几个互不相干的职权域
 *
 * 违规判定是组合式的：单个指标略高不必然违规，但「跨度大 + 体积大」必然违规。
 */
final class Policy
{
    /** 六个互不相干的业务职权域。策略以 JSON 数据保存，便于审阅，也避免审计器自审时把字面量误判成自身职权。 */
    public static function concerns(): array
    {
        static $table = null;
        if ($table === null) {
            $table = (array) json_decode((string) file_get_contents(__DIR__ . '/concerns.json'), true);
            unset($table['_comment']);
        }
        return $table;
    }

    /** 目录前缀 → 预算。核心最严，脚本最宽（但同样受组合规则约束）。 */
    public const BUDGETS = [
        'core'    => ['sloc' => 120, 'fanout' => 5, 'label' => '核心'],
        'systems' => ['sloc' => 190, 'fanout' => 8, 'label' => '系统层'],
        'themes'  => ['sloc' => 150, 'fanout' => 6, 'label' => '承载包'],
        'plugins' => ['sloc' => 150, 'fanout' => 6, 'label' => '承载包'],
        'pages'   => ['sloc' => 150, 'fanout' => 6, 'label' => '承载包'],
        'tools'   => ['sloc' => 240, 'fanout' => 8, 'label' => '脚本'],
        // 测试层的职责本就是跨单元验证，扇出天然高于生产代码；但行数、主体数、职权跨度一律不放宽。
        'tests'   => ['sloc' => 240, 'fanout' => 12, 'label' => '脚本'],
        'config'  => ['sloc' => 120, 'fanout' => 3, 'label' => '配置'],
        'public'  => ['sloc' => 80,  'fanout' => 4, 'label' => '入口'],
    ];

    public const MAX_CLASSES = 1;
    public const MAX_METHODS = 20;
    /** 业务职权跨度硬上限；触及即违规，无论体积。 */
    public const MAX_CONCERNS = 4;
    /** 跨度达到 3 时，体积超过预算的这一比例同样违规。 */
    public const COMBO_RATIO = 0.8;

    public static function budget(string $rel): array
    {
        $top = explode('/', $rel)[0];
        return self::BUDGETS[$top] ?? ['sloc' => 190, 'fanout' => 8, 'label' => '其它'];
    }

    /** @return array<string, mixed> 单个文件的全部指标与违规清单 */
    public static function metrics(string $path, string $rel): array
    {
        $src = (string) file_get_contents($path);
        $lower = strtolower($src);
        $sloc = 0;
        foreach (explode("\n", $src) as $line) {
            $t = trim($line);
            if ($t === '' || str_starts_with($t, '//') || str_starts_with($t, '#')
                || str_starts_with($t, '*') || str_starts_with($t, '/*') || str_starts_with($t, '?>')) {
                continue;
            }
            $sloc++;
        }

        preg_match_all('/^\s*(?:final\s+|abstract\s+)?(?:class|interface|trait|enum)\s+\w+/m', $src, $cm);
        preg_match_all('/^\s*public\s+(?:static\s+)?function\s+\w+/m', $src, $pm);
        preg_match_all('/^use\s+[A-Za-z0-9_\\\\]+\s*;/m', $src, $um);

        $concerns = [];
        foreach (self::concerns() as $domain => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($lower, $needle)) { $concerns[] = $domain; break; }
            }
        }
        sort($concerns);

        $budget = self::budget($rel);
        $m = [
            'path'           => $rel,
            'layer'          => $budget['label'],
            'sloc'           => $sloc,
            'sloc_limit'     => $budget['sloc'],
            'classes'        => count($cm[0]),
            'public_methods' => count($pm[0]),
            'fan_out'        => count($um[0]),
            'fanout_limit'   => $budget['fanout'],
            'concerns'       => $concerns,
            'concern_span'   => count($concerns),
            'violations'     => [],
        ];

        $add = static function (string $rule, string $detail) use (&$m): void {
            $m['violations'][] = ['rule' => $rule, 'detail' => $detail];
        };

        if ($sloc > $budget['sloc']) {
            $add('sloc', "有效代码行 {$sloc} > 预算 {$budget['sloc']}");
        }
        if ($m['classes'] > self::MAX_CLASSES) {
            $add('classes', "单文件责任主体 {$m['classes']} 个，应拆为独立文件");
        }
        if ($m['public_methods'] > self::MAX_METHODS) {
            $add('public_methods', "公开方法 {$m['public_methods']} 个，对外承诺过宽");
        }
        if ($m['fan_out'] > $budget['fanout']) {
            $add('fan_out', "扇出 {$m['fan_out']} > 预算 {$budget['fanout']}");
        }
        if ($m['concern_span'] >= self::MAX_CONCERNS) {
            $add('concern_span', "同时承担 {$m['concern_span']} 个职权域：" . implode('+', $concerns));
        } elseif ($m['concern_span'] >= 3 && $sloc > $budget['sloc'] * self::COMBO_RATIO) {
            $add('combo', "跨度 {$m['concern_span']} 且体积 {$sloc} 接近预算，属于职权聚合：" . implode('+', $concerns));
        }
        return $m;
    }
}
