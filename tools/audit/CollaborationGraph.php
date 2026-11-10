<?php
declare(strict_types=1);
namespace Wx\Tools\Audit;

/**
 * 协作图（规矩 I）。
 *
 * 从源码抽取真实存在的协作边，而不是照抄设计文档：
 *   - 契约：接口定义 / implements / 容器绑定
 *   - 事件：emit·apply 生产者 / on·filter 消费者
 *   - 接口：清单 operation / registerOperation 执行者
 *   - 引用：use 指向的类文件是否真实存在
 *
 * 「孤儿」= 有一端悬空的协作：引用了不存在的类、清单里没人实现的 operation、
 * 裁决型事件没人监听、契约永远没有实现者。这些正是协作最易悄悄断裂的地方。
 */
final class CollaborationGraph
{
    /** 裁决型事件：缺消费者会让功能静默失效，必须配对；通知型事件允许零监听。 */
    public const DECISION_EVENTS = ['render.should_render', 'route.resolve', 'package.enabled', 'kernel.exception'];

    public const MATRIX = [
        ['id' => 'C1', 'guard' => 'C1', 'name' => '契约面：接口 ↔ 实现 ↔ 容器绑定', 'edges' => 'contract', 'runtime' => '逐个 resolve 成功、替换实现后行为一致'],
        ['id' => 'C2', 'guard' => 'C2', 'name' => '装配面：system.json 依赖声明 ↔ 拓扑顺序', 'edges' => 'assembly', 'runtime' => '声明依赖必在自身之前、成环被拒'],
        ['id' => 'C3', 'guard' => 'C3', 'name' => '事件面：emit/apply 生产者 ↔ on/filter 消费者', 'edges' => 'event', 'runtime' => '钩子优先级、断链检测、跨系统注入'],
        ['id' => 'C4', 'guard' => 'C4', 'name' => '接口面：operation ↔ 执行者 ↔ 文档 ↔ OpenAPI', 'edges' => 'operation', 'runtime' => '四方 1:1、缺执行者返回 50101'],
        ['id' => 'C5', 'guard' => 'C5', 'name' => '装卸面：磁盘清单 ↔ 状态 ↔ 装载 ↔ 验签', 'edges' => 'lifecycle', 'runtime' => '停用包不装载、版本对账、篡改拒装'],
        ['id' => 'C6', 'guard' => 'C6', 'name' => '呈现面：路由 ↔ 裁决点 ↔ 渲染', 'edges' => 'render', 'runtime' => 'standalone 绕过主题、无主题零 HTML'],
        ['id' => 'C7', 'guard' => 'C7', 'name' => '数据面：契约 ↔ 真实表 ↔ 读回', 'edges' => 'data', 'runtime' => 'MySQL 写入读回、拔 DSN 优雅回退'],
        ['id' => 'C8', 'guard' => 'C8', 'name' => '编排面：编排者 ↔ 协作者', 'edges' => 'collaborator', 'runtime' => '协作轨迹覆盖全部协作者、协作者可独立验证'],
    ];

    /** @param list<string> $phpFiles 绝对路径 */
    public static function build(string $root, array $phpFiles): array
    {
        $sources = [];
        foreach ($phpFiles as $path) {
            $rel = ltrim(str_replace('\\', '/', str_replace($root, '', $path)), '/');
            $sources[$rel] = (string) file_get_contents($path);
        }
        $all = implode("\n", $sources);

        $contracts = self::contractNames($root);
        $implements = self::matchAll($all, '/\bimplements\s+([A-Za-z0-9_,\s\\\\]+?)(?:\s*\{|\s*$)/m');
        $bound = self::matchAll($all, '/->(?:bind|singleton)\(\s*([A-Za-z0-9_\\\\]+)::class/');

        $produced = self::tags($all, '/->(?:emit|apply)\(\s*\'([^\']+)\'/');
        $consumed = self::tags($all, '/->(?:on|filter)\(\s*\'([^\']+)\'/');

        $registry = json_decode((string) @file_get_contents($root . '/api/registry.json'), true);
        $listed = [];
        foreach ((array) ($registry['endpoints'] ?? []) as $e) {
            $listed[] = (string) $e['operation'];
        }
        $implemented = self::matchAll($all, '/->registerOperation\(\s*\'([^\']+)\'/');

        $edges = [];
        $orphans = [];

        // 1) use 引用必须指向真实存在的类文件
        foreach ($sources as $rel => $src) {
            preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)\s*;/m', $src, $m);
            foreach ($m[1] as $fqcn) {
                if (!str_starts_with($fqcn, 'Wx\\')) continue;
                $target = self::classFile($fqcn);
                $edges[] = ['type' => 'use', 'from' => $rel, 'to' => $fqcn];
                if ($target === null || !is_file($root . '/' . $target)) {
                    $orphans[] = ['type' => 'use', 'detail' => "{$rel} 引用了不存在的类 {$fqcn}"];
                }
            }
        }

        // 2) 每个契约必须有实现者与容器绑定（核心只声明接口，不实现）
        foreach ($contracts as $short) {
            $hasImpl = false;
            foreach ($implements as $clause) {
                foreach (explode(',', $clause) as $part) {
                    if (trim($part) === $short) $hasImpl = true;
                }
            }
            foreach ($bound as $b) {
                if (str_ends_with($b, '\\' . $short)) $hasImpl = true;
            }
            $edges[] = ['type' => 'contract', 'from' => "core/Contracts/{$short}.php", 'to' => $hasImpl ? 'implemented' : 'none'];
            if (!$hasImpl) {
                $orphans[] = ['type' => 'contract', 'detail' => "契约 {$short} 没有任何实现者或容器绑定"];
            }
        }

        // 3) 裁决型事件必须有消费者；同时记录全部事件边
        foreach ($produced as $tag) {
            $edges[] = ['type' => 'event', 'from' => 'producer', 'to' => $tag, 'consumers' => in_array($tag, $consumed, true) ? 1 : 0];
        }
        foreach (self::DECISION_EVENTS as $tag) {
            if (!in_array($tag, $consumed, true)) {
                $orphans[] = ['type' => 'event', 'detail' => "裁决型事件 {$tag} 没有任何消费者，功能会静默失效"];
            }
        }

        // 4) 清单 operation 与执行者 1:1
        foreach ($listed as $op) {
            $edges[] = ['type' => 'operation', 'from' => 'api/registry.json', 'to' => $op];
            if (!in_array($op, $implemented, true)) {
                $orphans[] = ['type' => 'operation', 'detail' => "清单 operation {$op} 没有注册执行者"];
            }
        }
        foreach ($implemented as $op) {
            if (!in_array($op, $listed, true)) {
                $orphans[] = ['type' => 'operation', 'detail' => "注册了未登记的 operation {$op}"];
            }
        }

        return [
            'matrix'  => self::MATRIX,
            'edges'   => $edges,
            'orphans' => $orphans,
            'summary' => [
                'contracts'        => count($contracts),
                'use_edges'        => count(array_filter($edges, static fn(array $e): bool => $e['type'] === 'use')),
                'event_producers'  => count($produced),
                'event_consumers'  => count($consumed),
                'operations'       => count($listed),
                'operations_bound' => count(array_intersect($listed, $implemented)),
            ],
        ];
    }

    /** @return list<string> */
    private static function contractNames(string $root): array
    {
        $out = [];
        foreach (glob($root . '/core/Contracts/*.php') ?: [] as $file) {
            $out[] = basename($file, '.php');
        }
        sort($out);
        return $out;
    }

    /** @return list<string> */
    private static function matchAll(string $src, string $pattern): array
    {
        preg_match_all($pattern, $src, $m);
        return array_values(array_unique(array_map('trim', $m[1] ?? [])));
    }

    /** @return list<string> */
    private static function tags(string $src, string $pattern): array
    {
        preg_match_all($pattern, $src, $m);
        return array_values(array_unique($m[1] ?? []));
    }

    /** 按 bootstrap.php 的命名空间映射解析目标文件；返回 null 表示无法解析。 */
    private static function classFile(string $fqcn): ?string
    {
        $map = [
            'Wx\\Contracts\\' => 'core/Contracts/',
            'Wx\\Core\\'      => 'core/',
            'Wx\\Systems\\'   => 'systems/',
            'Wx\\Themes\\'    => 'themes/',
            'Wx\\Plugins\\'   => 'plugins/',
            'Wx\\Pages\\'     => 'pages/',
            'Wx\\Tools\\Audit\\' => 'tools/audit/',
            'Wx\\Tools\\Bench\\' => 'tools/bench/',
            'Wx\\Tools\\Journey\\' => 'tools/journey/',
            'Wx\\Tools\\Facts\\' => 'tools/facts/',
            'Wx\\Tools\\Faults\\' => 'tools/faults/',
        ];
        foreach ($map as $prefix => $dir) {
            if (str_starts_with($fqcn, $prefix)) {
                return $dir . str_replace('\\', '/', substr($fqcn, strlen($prefix))) . '.php';
            }
        }
        return null;
    }
}
