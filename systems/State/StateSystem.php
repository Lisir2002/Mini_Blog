<?php
declare(strict_types=1);
namespace Wx\Systems\State;

use Throwable;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\AbstractSystem;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;
use Wx\Systems\Data\Db;

/**
 * 第 15 系统层 —— 状态与反馈。
 *
 * 它回答的不是「有没有出错」，而是四件更具体的事：
 *   现在处于哪个相位、卡在谁手里、为什么、下一步能做什么。
 * 五态与文案在此装配；呈现仍归主题（规矩 J），核心只给结构化数据。
 *
 * 依赖只声明 log：数据库是可选增强，连不上时自动退化为内存存储，
 * 「删光数据库也能启动」这条第一性原理不为状态层开例外。
 */
final class StateSystem extends AbstractSystem
{
    public function name(): string { return 'state'; }

    public function register(Kernel $kernel): void
    {
        $kernel->container()->singleton(
            StateStoreInterface::class,
            static function () use ($kernel): StateStoreInterface {
                $db = Db::optional($kernel);
                return $db === null ? new MemoryStateStore() : new PdoStateStore($db);
            },
            'system:state',
            10
        );

        $kernel->container()->singleton(
            StateFactory::class,
            static fn(): StateFactory => new StateFactory(new StateCopy($kernel->hooks())),
            'system:state',
            10
        );

        $kernel->container()->singleton(
            StateTasks::class,
            static function () use ($kernel): StateTasks {
                /** @var StateStoreInterface $store */
                $store = $kernel->container()->resolve(StateStoreInterface::class);
                return new StateTasks($store, (int) $kernel->config()->get('state.task_ttl', 86400));
            },
            'system:state',
            10
        );
    }

    public function boot(Kernel $kernel): void
    {
        if (!$kernel->container()->has(StateStoreInterface::class)) return;
        $kernel->container()->resolve(StateStoreInterface::class)->ensureSchema();
        $kernel->hooks()->filter('kernel.exception', self::handleException(...), 10);

        if (!$kernel->container()->has(ApiRegistryInterface::class)) return;
        /** @var StateTasks $tasks */
        $tasks = $kernel->container()->resolve(StateTasks::class);
        /** @var ApiRegistryInterface $registry */
        $registry = $kernel->container()->resolve(ApiRegistryInterface::class);
        (new StateOperations($tasks))->register($registry);
    }

    /**
     * 未捕获异常的统一出口。核心只负责「捕获并询问」，
     * 这里负责把它翻译成一个五态信封；没有本系统时核心退化为纯文本 500，不产出任何标签。
     */
    private static function handleException(mixed $response, Throwable $e, Request $request, Kernel $k): mixed
    {
        if ($response instanceof Response) return $response;

        /** @var StateFactory $factory */
        $factory = $k->container()->resolve(StateFactory::class);
        $trace = (string) ($request->header('X-Request-Id') ?? '');
        $state = $factory->fromThrowable($e, $trace === '' ? null : $trace);

        $out = (new Response())
            ->setStatus($state->httpStatus())
            ->setPayload([
                'title'  => $state->text()['title'],
                'state'  => $state->toArray(),
                'blocks' => [],
            ]);

        // 明确要求 JSON 的调用方不套主题：交给渲染阶段退化为纯数据信封。
        $accept = strtolower((string) ($request->header('Accept') ?? ''));
        if (str_contains($accept, 'application/json')) $out->setLayout('none');
        return $out;
    }
}
