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
 *
 * v0.67 A3：装载顺序从「只按 priority」升级为「依赖优先 + priority 稳定次序」。
 * 只对本批已通过装载裁决的包排序；指向批外的依赖交给装卸层做准入校验
 * （核心不解释跨类型依赖，也不因图外依赖拒绝装载）。
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
        return $this->topological($found);
    }

    /** 批内稳定拓扑：DFS 先输出依赖，同层保持 priority 排序后的原次序。 */
    private function topological(array $found): array
    {
        $edges = [];
        foreach ($found as $slug => $pkg) {
            $deps = [];
            foreach ((array) ($pkg['meta']['requires'] ?? []) as $dep) {
                $id = explode('@', (string) $dep, 2)[0];
                if (isset($found[$id])) $deps[] = $id;
            }
            $edges[$slug] = $deps;
        }
        $done = [];
        $visiting = [];
        $out = [];
        $visit = function (string $slug) use (&$visit, &$done, &$visiting, &$out, $edges): void {
            if (isset($done[$slug])) return;
            if (isset($visiting[$slug])) {
                throw new RuntimeException('承载包依赖成环，无法确定装载顺序：' . $slug);
            }
            $visiting[$slug] = true;
            foreach ($edges[$slug] ?? [] as $dep) $visit($dep);
            unset($visiting[$slug]);
            $done[$slug] = true;
            $out[] = $slug;
        };
        foreach (array_keys($found) as $slug) $visit($slug);
        $ordered = [];
        foreach ($out as $slug) $ordered[$slug] = $found[$slug];
        return $ordered;
    }
}
