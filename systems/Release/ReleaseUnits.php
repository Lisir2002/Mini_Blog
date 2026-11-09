<?php
declare(strict_types=1);
namespace Wx\Systems\Release;

/**
 * 包内单元差异 —— 比较「源包」与「当前站点」的 system.json 版本。
 *
 * 站点程序升级不只是 core/ 目录：功能都在 systems/ 里，迁移脚本也在那里。
 * 只换 core 而不换 systems，会出现「代码是新的、表和实现是旧的」这种最难排查的状态。
 */
final class ReleaseUnits
{
    public function __construct(private string $root) {}

    public function role(): string { return 'release_units'; }

    /**
     * 源包里版本高于当前站点的系统层；版本相同视为不升级（避免无意义快照）。
     * @return list<array{slug: string, from: string, to: string, dir: string}>
     */
    public function diff(string $source): array
    {
        $out = [];
        foreach (glob(rtrim($source, '/') . '/systems/*/system.json') ?: [] as $file) {
            $pkg = $this->read($file);
            $slug = (string) ($pkg['name'] ?? basename(dirname($file)));
            if ($slug === '') continue;
            $currentFile = $this->root . '/systems/' . basename(dirname($file)) . '/system.json';
            if (!is_file($currentFile)) continue;
            $current = $this->read($currentFile);
            $from = (string) ($current['version'] ?? '0.0.0');
            $to = (string) ($pkg['version'] ?? '0.0.0');
            if (version_compare($to, $from, '<=')) continue;
            $out[] = ['slug' => $slug, 'from' => $from, 'to' => $to, 'dir' => dirname($file)];
        }
        usort($out, static fn(array $a, array $b): int => strcmp($a['slug'], $b['slug']));
        return $out;
    }

    /** @return array<string, mixed> */
    private function read(string $file): array
    {
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }
}
