<?php
declare(strict_types=1);
namespace Wx\Core;

use Wx\Contracts\RendererInterface;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;

/**
 * 系统核心 · 掌管全局
 *
 * 只负责：生命周期、契约容器、事件总线、系统层装配、请求分发、异常边界。
 * 不负责：任何业务功能、任何数据表、任何 HTML / CSS / JavaScript。
 */
final class Kernel
{
    private Config $config;
    private Container $container;
    private HookBus $hooks;
    private SystemRegistry $systems;
    private Router $router;
    private bool $booted = false;

    /** @param array<string, mixed> $configOverride 运行期配置覆盖（多站点 / 嵌入 / 测试） */
    public function __construct(private string $root, array $configOverride = [])
    {
        $this->config    = new Config($root, $configOverride);
        $this->hooks     = new HookBus();
        $this->container = new Container(
            fn(string $contract, string $from, string $to, int $priority): bool =>
                (bool) $this->hooks->apply('contract.override', true, $contract, $from, $to, $priority, $this)
        );
        $this->systems   = new SystemRegistry($this);
        $this->router    = new Router();
    }

    public function root(): string { return $this->root; }
    public function config(): Config { return $this->config; }
    public function container(): Container { return $this->container; }
    public function hooks(): HookBus { return $this->hooks; }
    public function systems(): SystemRegistry { return $this->systems; }

    public function boot(): void
    {
        if ($this->booted) return;
        $this->hooks->emit('kernel.booting', $this);
        $order = $this->systems->discover();
        $this->systems->registerAll($order);
        $this->booted = true;
        $this->trace('内核装配完成', ['systems' => $order]);
        $this->hooks->emit('kernel.ready', $this);
    }

    /** 纯处理流程，不产生任何输出，便于 CLI / 单元测试直接调用。 */
    public function handle(Request $request): Response
    {
        $this->boot();
        $response = $this->router->resolve($request, $this);
        $this->hooks->emit('kernel.response', $request, $response, $this);
        $response = $this->hooks->apply('kernel.response.filter', $response, $request, $this);
        $response = $this->renderStage($response, $request);
        $this->hooks->emit('kernel.shutdown', $request, $response, $this);
        return $response;
    }

    /**
     * 零呈现保证（结构性，而非口头约定）：
     *   1. 核心自身永不产出 HTML / CSS / JS；
     *   2. 只有「主题系统」装载的主题绑定了 RendererInterface，才由主题全权渲染；
     *   3. 无主题 → 框架退化为纯数据服务（Headless），body 为空或仅为 JSON。
     */
    private function renderStage(Response $response, Request $request): Response
    {
        // 布局槽位是「意图」不是「样式」：页面声明想要 site/admin/tool/...，
        // 由主题包决定有没有、长什么样。核心只搬运字符串，不解释、不兜底、不产出标签。
        $response->setLayout((string) $this->hooks->apply(
            'render.layout', $response->layout(), $response, $request, $this
        ));

        // 「是否套用主题渲染」同样是可编程裁决点：插件可声明自包含响应。
        // 注意：这里只允许「不渲染」，绝不允许「绕开主题另写一套样式」。
        $shouldRender = (bool) $this->hooks->apply(
            'render.should_render', true, $response, $request, $this
        );
        if ($shouldRender && $this->container->has(RendererInterface::class)) {
            /** @var RendererInterface $renderer */
            $renderer = $this->container->resolve(RendererInterface::class);
            // 渲染器可以合法地「不接这个布局」：不写 body 即为放弃呈现权。
            $response = $renderer->render($response, $this);
        }
        // 没有任何呈现层写过内容 → 退化为纯数据（零标签、零样式），核心绝不兜底输出 HTML。
        if ($response->body() === '' && $response->payload() !== null) {
            $response->asData();
        }
        return $response;
    }

    public function run(): void
    {
        $request = Request::fromGlobals();
        try {
            $this->handle($request)->send();
        } catch (\Throwable $e) {
            // 异常边界：核心只负责「捕获并询问」，翻译成什么样由状态系统决定。
            // 没有订阅者时退化为纯文本 500 —— 这是唯一允许核心自己写出的内容，且不含任何标签。
            $response = $this->hooks->apply('kernel.exception', null, $e, $request, $this);
            if (!$response instanceof Response) {
                $this->sendBareError();
                return;
            }
            try {
                if ($response->body() === '') $response = $this->renderStage($response, $request);
            } catch (\Throwable) {
                $this->sendBareError();
                return;
            }
            $response->send();
        }
    }

    /** 最后一道兜底：不套主题、不猜语言、不输出堆栈。 */
    private function sendBareError(): void
    {
        if (headers_sent()) return;
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "500 Internal Server Error\n";
    }

    /** 内核自身的可观测性出口，交由日志系统订阅；核心不写日志文件。 */
    public function trace(string $message, array $context = []): void
    {
        $this->hooks->emit('kernel.trace', $message, $context);
    }
}
