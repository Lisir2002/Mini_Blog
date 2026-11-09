<?php
declare(strict_types=1);
namespace Wx\Tools\Audit;

/**
 * 职责审计引擎 —— CLI 工具与守卫测试共用同一份实现。
 * 测试直接调用 run() 重新计算，因此「所验即最新」，不会读到过期报告。
 */
final class Auditor
{
    /** @return array<string, string> 相对路径 => 绝对路径 */
    public static function phpFiles(string $root): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') continue;
            $rel = ltrim(str_replace('\\', '/', str_replace($root, '', $file->getPathname())), '/');
            foreach (['.codebuddy/', 'storage/', 'docs/', 'vendor/'] as $skip) {
                if (str_starts_with($rel, $skip)) continue 2;
            }
            $files[$rel] = $file->getPathname();
        }
        ksort($files);
        return $files;
    }

    /** @return array<string, mixed> */
    public static function run(string $root): array
    {
        $files = self::phpFiles($root);
        $metrics = [];
        $violations = [];
        foreach ($files as $rel => $path) {
            $m = Policy::metrics($path, $rel);
            $metrics[] = $m;
            foreach ($m['violations'] as $v) {
                $violations[] = ['path' => $rel, 'rule' => $v['rule'], 'detail' => $v['detail']];
            }
        }
        $graph = CollaborationGraph::build($root, array_values($files));
        $heaviest = $metrics;
        usort($heaviest, static fn(array $a, array $b): int => $b['sloc'] <=> $a['sloc']);

        return [
            'generated_at' => date(DATE_ATOM),
            'tool'         => 'tools/audit-responsibility.php',
            'policy'       => [
                'budgets'       => Policy::BUDGETS,
                'max_classes'   => Policy::MAX_CLASSES,
                'max_methods'   => Policy::MAX_METHODS,
                'max_concerns'  => Policy::MAX_CONCERNS,
                'combo_ratio'   => Policy::COMBO_RATIO,
                'concern_table' => 'tools/audit/concerns.json',
            ],
            'summary'      => [
                'php_files'       => count($metrics),
                'total_sloc'      => array_sum(array_column($metrics, 'sloc')),
                'max_sloc'        => $heaviest[0]['sloc'] ?? 0,
                'violation_count' => count($violations),
                'orphan_count'    => count($graph['orphans']),
            ],
            'files'         => $metrics,
            'violations'    => $violations,
            'collaboration' => $graph,
        ];
    }

    /** @param array<string, mixed> $report */
    public static function write(string $root, array $report): void
    {
        if (!is_dir($root . '/docs')) mkdir($root . '/docs', 0775, true);
        file_put_contents(
            $root . '/docs/responsibility-report.json',
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }
}
