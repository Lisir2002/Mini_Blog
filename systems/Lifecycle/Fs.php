<?php
declare(strict_types=1);
namespace Wx\Systems\Lifecycle;

/** 纯 PHP 文件系统工具：递归复制 / 删除 / 哈希清单。不依赖任何第三方库。 */
final class Fs
{
    public static function within(string $path, string $root): bool
    {
        $real = realpath($path);
        $base = realpath($root);
        if ($real === false || $base === false) return false;
        return $real === $base || str_starts_with($real, $base . DIRECTORY_SEPARATOR);
    }

    public static function copyDir(string $src, string $dst): void
    {
        if (!is_dir($src)) {
            throw new LifecycleException('源目录不存在：' . $src, 42201);
        }
        if (!is_dir($dst) && !@mkdir($dst, 0775, true) && !is_dir($dst)) {
            throw new LifecycleException('无法创建目标目录：' . $dst, 50001);
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($items as $item) {
            $target = $dst . DIRECTORY_SEPARATOR . $items->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($target) && !@mkdir($target, 0775, true) && !is_dir($target)) {
                    throw new LifecycleException('无法创建子目录：' . $items->getSubPathName(), 50001);
                }
            } elseif (!@copy($item->getPathname(), $target)) {
                throw new LifecycleException('复制文件失败：' . $items->getSubPathName(), 50001);
            }
        }
    }

    public static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    /** @return array<string, string> 相对路径 => sha256（排除清单与签名文件自身，避免自指） */
    public static function hashDir(string $dir): array
    {
        $out = [];
        if (!is_dir($dir)) return $out;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($items as $item) {
            if (!$item->isFile()) continue;
            $rel = str_replace('\\', '/', $items->getSubPathName());
            if ($rel === 'manifest.sha256' || $rel === 'signature.json') continue;
            $out[$rel] = hash_file('sha256', $item->getPathname()) ?: '';
        }
        ksort($out);
        return $out;
    }

    /** 规范化的待签串：文件清单按键排序后 JSON 编码，双方必须一致。 */
    public static function canonical(string $dir): string
    {
        return (string) json_encode(self::hashDir($dir), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * 落位基线：安装 / 升级 / 回滚成功后登记进台账，供启动期复验比对。
     *
     * 只记「载荷文件规范化摘要」，不记目录里自带的 manifest / signature
     * （hashDir 已排除这两者）——那两份文件会随包一起被改写，不构成可信锚。
     * $secret 非空时额外带 HMAC，防止「只改台账不改包」的绕过。
     *
     * @return array{algo: string, digest: string, files: int, mac?: string}
     */
    public static function baseline(string $dir, string $secret = ''): array
    {
        $digest = hash('sha256', self::canonical($dir));
        $out = ['algo' => 'sha256', 'digest' => $digest, 'files' => count(self::hashDir($dir))];
        if ($secret !== '') $out['mac'] = hash_hmac('sha256', $digest, $secret);
        return $out;
    }
}
