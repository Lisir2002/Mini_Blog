<?php
declare(strict_types=1);
namespace Wx\Core;

use RuntimeException;
use Wx\Contracts\SystemInterface;

/**
 * 系统层装配器 —— 「可接入新的系统层」的落点。
 *
 * 新增一个系统层 = 丢一个目录 + 一份 system.json，零改动内核、零改动配置。
 * 装配流程：发现清单 → 依赖拓扑排序（含环检测）→ 两阶段 register → 两阶段 boot。
 * 两阶段的意义：所有契约先全部绑定完毕，任何系统在 boot 阶段都能安全地解析别人的契约。
 */
final class SystemRegistry
{
    private array $manifests = [];
    private array $instances = [];
    private array $order = [];

    public function __construct(private Kernel $kernel) {}

    /** @return list<string> 装配顺序 */
    public function discover(): array
    {
        $root = $this->kernel->root() . '/systems';
        $disabled = (array) $this->kernel->config()->get('systems.disabled', []);
        foreach (glob($root . '/*/system.json') ?: [] as $file) {
            $meta = json_decode((string) file_get_contents($file), true);
            if (!is_array($meta)) {
                throw new RuntimeException('系统层清单解析失败：' . $file);
            }
            $name = $meta['name'] ?? basename(dirname($file));
            if (($meta['enabled'] ?? true) === false || in_array($name, $disabled, true)) {
                continue;
            }
            // 同名冲突检测：两个包声明同一个 name 会静默后者覆盖前者（前者凭空消失）。
            // name 是系统层的全局唯一标识，必须在发现期就拦下。
            if (isset($this->manifests[$name])) {
                throw new RuntimeException(
                    "系统层重名冲突：{$name}（" . dirname($file) . '）与已发现的同名系统层重复；'
                    . 'name 必须全局唯一'
                );
            }
            $this->manifests[$name] = $meta + [
                'priority' => 10, 'requires' => [], 'class' => '', 'version' => '0.0.0',
            ];
        }
        // 发现期即校验 class 非空：原实现要等到 registerAll 才报「类不存在」，
        // 错误信息模糊且已过装配点。空 class 视为配置残缺，提前失败。
        foreach ($this->manifests as $name => $meta) {
            if (($meta['class'] ?? '') === '') {
                throw new RuntimeException("系统层 {$name} 的 system.json 缺少 class 字段，无法装配");
            }
        }
        $this->order = $this->topologicalOrder();
        return $this->order;
    }

    public function registerAll(array $order): void
    {
        foreach ($order as $name) {
            $meta = $this->manifests[$name];
            $class = (string) $meta['class'];
            if ($class === '' || !class_exists($class)) {
                throw new RuntimeException("系统层 {$name} 的类不存在：{$class}");
            }
            $system = new $class();
            if (!$system instanceof SystemInterface) {
                throw new RuntimeException("{$class} 必须实现 " . SystemInterface::class);
            }
            $this->instances[$name] = $system;
            $system->register($this->kernel);
            $this->kernel->hooks()->emit('system.registered', $name, $system);
        }
        foreach ($order as $name) {
            $this->instances[$name]->boot($this->kernel);
            $this->kernel->hooks()->emit('system.booted', $name, $this->instances[$name]);
        }
    }

    public function instances(): array { return $this->instances; }
    public function manifests(): array { return $this->manifests; }
    public function order(): array { return $this->order; }

    /** @return list<string> */
    private function topologicalOrder(): array
    {
        $visited = [];
        $visiting = [];
        $out = [];

        $visit = function (string $name) use (&$visit, &$visited, &$visiting, &$out): void {
            if (isset($visited[$name])) return;
            if (isset($visiting[$name])) {
                throw new RuntimeException('系统层依赖成环，无法确定装配顺序：' . $name);
            }
            $visiting[$name] = true;
            foreach ((array) ($this->manifests[$name]['requires'] ?? []) as $dep) {
                if (!isset($this->manifests[$dep])) {
                    throw new RuntimeException("系统层 {$name} 依赖的 {$dep} 未安装或未启用");
                }
                $visit($dep);
            }
            unset($visiting[$name]);
            $visited[$name] = true;
            $out[] = $name;
        };

        $names = array_keys($this->manifests);
        usort($names, fn(string $a, string $b): int =>
            ((int) $this->manifests[$a]['priority']) <=> ((int) $this->manifests[$b]['priority'])
            ?: strcmp($a, $b)
        );
        foreach ($names as $name) {
            $visit($name);
        }
        return $out;
    }
}
