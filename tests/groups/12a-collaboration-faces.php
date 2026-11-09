<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/tools/audit/Policy.php';

use Wx\Contracts\ApiRegistryInterface;
use Wx\Contracts\ContentRepositoryInterface;
use Wx\Core\Container;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Systems\Api\ApiRegistry;
use Wx\Systems\Content\MemoryContentRepository;
use Wx\Tools\Audit\Policy;

/** @return array<string, mixed> */
function collabReport(string $root): array
{
    return (array) json_decode((string) file_get_contents($root . '/docs/responsibility-report.json'), true);
}

echo "\n=== 12. 协作全方位验证（规矩 I） ===\n";

check('C0 审计报告可重跑：抽样重算与报告一致，零违规零孤儿', function () use ($root): void {
    $report = collabReport($root);
    truthy(($report['summary']['php_files'] ?? 0) > 0, '审计报告未生成');
    truthy($report['violations'] === [], '仍存在单文件职责违规');
    truthy($report['collaboration']['orphans'] === [], '仍存在孤儿协作（悬空引用）');
    foreach (['core/Kernel.php', 'systems/Api/ApiDispatcher.php', 'tests/helpers.php'] as $rel) {
        $fresh = Policy::metrics($root . '/' . $rel, $rel);
        $in = null;
        foreach ($report['files'] as $f) {
            if ($f['path'] === $rel) $in = $f;
        }
        truthy($in !== null && $in['sloc'] === $fresh['sloc'] && $in['concerns'] === $fresh['concerns'],
            '报告指标与重算不一致：' . $rel);
    }
});

check('C1 契约面：每个契约都有实现且运行时可解析；替换实现后下游行为一致', function () use ($root): void {
    $report = collabReport($root);
    $orphans = array_filter($report['collaboration']['orphans'],
        static fn(array $o): bool => $o['type'] === 'contract');
    truthy($orphans === [], '存在没有实现者的契约');

    $k = contractKernel(null);
    $k->boot();
    foreach ($k->container()->contracts() as $contract) {
        truthy(is_object($k->container()->resolve($contract)), '契约无法解析：' . $contract);
    }

    // 协作退化注入：把内容契约换成内存实现，首页数据链路必须依旧贯通
    $k->container()->bind(ContentRepositoryInterface::class,
        static fn(): ContentRepositoryInterface => new MemoryContentRepository(), 'guard:c1', 20);
    $data = json_decode($k->handle(new Request('GET', '/'))->body(), true);
    truthy(in_array('第一篇文章', $data['items'] ?? [], true), '替换实现后首页数据链路断裂');
    truthy(count($k->container()->overrides()) >= 1, '契约替换未留痕');

    // 契约容器自身的覆盖裁决可独立验证
    $c = new Container();
    $c->bind(ContentRepositoryInterface::class, static fn() => new stdClass(), 'a', 10);
    $c->bind(ContentRepositoryInterface::class, static fn() => new stdClass(), 'b', 20);
    truthy($c->ownerOf(ContentRepositoryInterface::class) === 'b', '契约覆盖裁决异常');
});

check('C2 装配面：清单 requires 逐条对账拓扑顺序，停用被依赖层明确报错', function () use ($root): void {
    $k = contractKernel(null);
    $k->boot();
    $pos = array_flip($k->systems()->order());
    foreach (glob($root . '/systems/*/system.json') ?: [] as $file) {
        $meta = json_decode((string) file_get_contents($file), true);
        $name = (string) ($meta['name'] ?? basename(dirname($file)));
        if (!isset($pos[$name])) continue;
        foreach ((array) ($meta['requires'] ?? []) as $dep) {
            truthy(isset($pos[$dep]) && $pos[$dep] < $pos[$name],
                "{$name} 声明依赖 {$dep}，但拓扑顺序未排在其之前");
        }
    }
    // 断链注入：停掉被依赖的 database，必须报出依赖未满足，不能静默装配
    $threw = false;
    try {
        (new Kernel($root, ['systems' => ['disabled' => ['database']]]))->boot();
    } catch (RuntimeException $e) {
        $threw = str_contains($e->getMessage(), '依赖');
    }
    truthy($threw, '停用被依赖系统层时应明确报出依赖未满足');
});

check('C3 事件面：裁决型事件均有消费者，且渲染裁决点确实被核心询问', function () use ($root): void {
    $report = collabReport($root);
    $consumers = [];
    foreach ($report['collaboration']['edges'] as $edge) {
        if ($edge['type'] === 'event') $consumers[$edge['to']] = $edge['consumers'];
    }
    foreach (['render.should_render', 'route.resolve', 'package.enabled'] as $tag) {
        truthy(($consumers[$tag] ?? 0) > 0, '裁决型事件缺少消费者：' . $tag);
    }

    $k = kernel('bare');
    $k->boot();
    $seen = false;
    $k->hooks()->filter('render.should_render',
        static function (bool $value) use (&$seen): bool { $seen = true; return $value; }, 50);
    $k->handle(new Request('GET', '/'));
    truthy($seen, 'renderStage 未询问渲染裁决点，协作链路断裂');
    $tags = $k->hooks()->tags();
    truthy($tags['actions'] !== [] && $tags['filters'] !== [], '事件总线缺少 action 或 filter 通道');
});

check('C4 接口面：operation / 执行者 / 文档 / OpenAPI 四方 1:1，缺执行者规范化为 50101', function () use ($root): void {
    $registry = new ApiRegistry($root . '/api/registry.json');
    $ops = array_column($registry->endpoints(), 'operation');
    truthy(count($ops) === count(array_unique($ops)), '清单 operation 存在重复');
    truthy($registry->documents($root) === [], '接口文档与清单不一致');

    $doc = json_decode((string) file_get_contents($root . '/docs/api/openapi.json'), true);
    $openIds = [];
    foreach ($doc['paths'] as $methods) {
        foreach ($methods as $op) $openIds[] = (string) ($op['operationId'] ?? '');
    }
    truthy(count($openIds) === count($ops) && array_diff($ops, $openIds) === [],
        'OpenAPI operationId 与清单不 1:1');

    // 断链注入：注册表只载入清单、未登记执行者
    $threw = false;
    try {
        $registry->execute('system.info', []);
    } catch (\Wx\Systems\Api\ApiOperationException $e) {
        $threw = $e->apiCode === 50101;
    }
    truthy($threw, '缺少执行者时应返回 50101，而不是静默失败');

    $k = contractKernel(null);
    truthy($k->container()->has(ApiRegistryInterface::class) === false, '未装配时不应凭空出现接口注册表');
    $k->boot();
    truthy($k->container()->has(ApiRegistryInterface::class), '装配后接口注册表未绑定');

    // 示例漂移守卫：清单里的响应示例必须与真实接口响应逐字段一致
    $live = json_decode($k->handle(new Request('GET', '/api/v1/system/info'))->body(), true)['data'] ?? [];
    $example = null;
    foreach ($registry->endpoints() as $endpoint) {
        if ($endpoint['id'] === 'API-CORE-001') $example = $endpoint['example_response']['data'] ?? null;
    }
    truthy(is_array($example), '清单缺少 API-CORE-001 响应示例');
    foreach (['core_version', 'systems', 'contracts', 'api_operations', 'headless'] as $field) {
        truthy(($example[$field] ?? null) === ($live[$field] ?? null),
            "清单示例与真实响应不一致：{$field}（示例 " . var_export($example[$field] ?? null, true)
            . ' / 实际 ' . var_export($live[$field] ?? null, true) . '）');
    }
});
