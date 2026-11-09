<?php
declare(strict_types=1);
namespace Wx\Systems\Api;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\AbstractSystem;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;

/**
 * API 接口系统 —— 把「程序各项操作」规范化为接口。
 *
 * 清单（api/registry.json）是唯一事实源。本文件只做两件事：
 * 装配期把 operation 绑定到只读盘点协作者，启动期声明 API 路由前缀。
 * 内核级只读数据由 SystemIntrospection 提供，业务执行者由各系统层自行登记。
 */
final class ApiSystem extends AbstractSystem
{
    public function name(): string { return 'api'; }

    public function register(Kernel $kernel): void
    {
        $registry = new ApiRegistry($kernel->root() . '/api/registry.json');
        $kernel->container()->singleton(
            ApiRegistryInterface::class,
            static fn(): ApiRegistry => $registry,
            'system:api',
            10
        );

        $probe = new SystemIntrospection($kernel, $registry);
        $registry->registerOperation('system.info',      static fn(array $in): array => $probe->info());
        $registry->registerOperation('system.health',    static fn(array $in): array => $probe->health());
        $registry->registerOperation('system.contracts', static fn(array $in): array => $probe->contracts());
        $registry->registerOperation('system.layers',    static fn(array $in): array => $probe->layers());
        $registry->registerOperation('logs.query',       static fn(array $in): array => $probe->logs($in));
    }

    public function boot(Kernel $kernel): void
    {
        /** @var ApiRegistry $registry */
        $registry = $kernel->container()->resolve(ApiRegistryInterface::class);

        // priority=5：早于独立页（10）声明 API 路由；核心本身依旧零内置路由
        $kernel->hooks()->on('route.resolve', static function (Request $request, Response $response, Kernel $k) use ($registry): void {
            $prefix = (string) $k->config()->get('api.prefix', '/api/v1');
            if (!str_starts_with($request->path(), $prefix . '/') && $request->path() !== $prefix) {
                return;
            }
            $result = (new ApiDispatcher($registry, $k))->dispatch($request);
            foreach ((array) ($result['headers'] ?? []) as $key => $value) {
                $response->setHeader((string) $key, (string) $value);
            }
            $response
                ->setStatus($result['status'])
                ->setHeader('Content-Type', 'application/json; charset=utf-8')
                ->setBody(json_encode($result['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
        }, 5);
    }
}
