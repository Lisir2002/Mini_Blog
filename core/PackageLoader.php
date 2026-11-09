<?php
declare(strict_types=1);
namespace Wx\Core;

use RuntimeException;
use Wx\Contracts\PackageInterface;

/**
 * 承载包装配器 —— 主题 / 插件 / 独立页共用同一套装配协议。
 *
 * 三者都是 PackageInterface 的实现，只是目录与清单文件名不同。
 * 系统层负责「装载」，包负责「内容」，核心只提供装配基础设施。
 */
final class PackageLoader
{
    public function __construct(
        private Kernel $kernel,
        private string $baseDir,
        private string $manifestName
    ) {}

    /**
     * @param list<string>|null $only 白名单；null 表示装载全部已启用的包
     * @return array<string, array{meta: array, instance: PackageInterface, dir: string}>
     */
    public function load(?array $only = null): array
    {
        if (!is_dir($this->baseDir)) return [];
        $found = [];
        foreach (glob($this->baseDir . '/*/' . $this->manifestName) ?: [] as $file) {
            $meta = json_decode((string) file_get_contents($file), true);
            if (!is_array($meta) || ($meta['enabled'] ?? true) === false) continue;
            $slug = (string) ($meta['slug'] ?? basename(dirname($file)));
            if ($only !== null && !in_array($slug, $only, true)) continue;

            // 启用权交给装卸系统裁决；无订阅者时保持原行为（默认启用）
            $allowed = $this->kernel->hooks()->apply('package.enabled', true, $slug, $this->manifestName, $this->kernel);
            if (!$allowed) continue;
            $class = (string) ($meta['class'] ?? '');
            if ($class === '' || !class_exists($class)) {
                throw new RuntimeException("包 {$slug} 的类不存在：{$class}");
            }
            $object = new $class();
            if (!$object instanceof PackageInterface) {
                throw new RuntimeException("{$class} 必须实现 " . PackageInterface::class);
            }
            $found[$slug] = ['meta' => $meta, 'instance' => $object, 'dir' => dirname($file)];
        }
        uasort($found, fn(array $a, array $b): int =>
            ((int) ($a['meta']['priority'] ?? 10)) <=> ((int) ($b['meta']['priority'] ?? 10))
        );
        return $found;
    }
}
