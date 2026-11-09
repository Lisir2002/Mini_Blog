<?php
declare(strict_types=1);
namespace Wx\Systems\Release;

/**
 * 版本清单 —— 只认 version.json，不做任何 I/O 之外的事。
 *
 * 清单是「这个包是什么」的唯一声明；目录名、时间戳都不算版本。
 * 版本号必须是 SemVer：MAJOR.MINOR.PATCH，可选预发布后缀。
 */
final class ReleaseManifest
{
    private const SEMVER = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/';

    /** @return array<string, mixed> */
    public static function read(string $dir): array
    {
        $file = rtrim($dir, '/') . '/version.json';
        if (!is_file($file)) {
            throw new ReleaseException('版本清单缺失：' . $file, 42201);
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) throw new ReleaseException('版本清单不是合法 JSON：' . $file, 42201);
        $version = (string) ($data['version'] ?? '');
        if (preg_match(self::SEMVER, $version) !== 1) {
            throw new ReleaseException('版本号不符合 SemVer：' . $version, 42201);
        }
        $data['version'] = $version;
        return $data;
    }

    /** @param array<string, mixed> $from @param array<string, mixed> $to @param array<string, mixed> $options */
    public static function assertUpgradable(array $from, array $to, array $options = []): void
    {
        $old = (string) $from['version'];
        $new = (string) $to['version'];
        if (($options['allow_core_update'] ?? false) !== true) {
            throw new ReleaseException('站点程序升级默认关闭，请显式开启 release.allow_core_update', 40301);
        }
        if (version_compare($new, $old, '<=')) {
            throw new ReleaseException("目标版本 {$new} 不高于当前版本 {$old}", 40901);
        }
        $requires = (string) ($to['min_php'] ?? $to['requires_php'] ?? '');
        $minPhp = ltrim($requires, '>=');
        if ($minPhp !== '' && version_compare(PHP_VERSION, $minPhp, '<')) {
            throw new ReleaseException("目标版本要求 PHP {$requires}，当前 " . PHP_VERSION, 40901);
        }
    }

    /** @param array<string, mixed> $manifest */
    public static function version(array $manifest): string
    {
        return (string) ($manifest['version'] ?? '0.0.0');
    }
}
