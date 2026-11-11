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
        $started = microtime(true);
        $this->boot();
        $response = $this->router->resolve($request, $this);
        $this->hooks->emit('kernel.response', $request, $response, $this);
        $response = $this->hooks->apply('kernel.response.filter', $response, $request, $this);
        $response = $this->renderStage($response, $request);
        // 时长为「进入 handle 到渲染完成」：不用 REQUEST_TIME_FLOAT，CLI / 测试里那是进程启动时间，
        // 会把「脚本跑了 3 秒」记成「这次请求慢了 3 秒」——统计宁可标注口径，也不能给出假数字。
        $this->hooks->emit(
            'kernel.shutdown', $request, $response, $this,
            round((microtime(true) - $started) * 1000, 3)
        );
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
        // 3xx 没有实体：重定向只需要状态行与 Location，不应被主题包成一张页面。
        $isRedirect = $response->status() >= 300 && $response->status() < 400;
        if ($shouldRender && !$isRedirect && $this->container->has(RendererInterface::class)) {
            /** @var RendererInterface $renderer */
            $renderer = $this->container->resolve(RendererInterface::class);
            // 渲染器可以合法地「不接这个布局」：不写 body 即为放弃呈现权。
            $response = $renderer->render($response, $this);
        }
        // 没有任何呈现层写过内容 → 退化为纯数据（零标签、零样式），核心绝不兜底输出 HTML。
        // 重定向没有实体：3xx 既不该被主题包成页面，也不该把默认 payload 写成 JSON 正文。
        if (!$isRedirect && $response->body() === '' && $response->payload() !== null) {
            $response->asData();
        }
        return $response;
    }

    /**
     * 唯一入口：把「构造」也纳入异常边界。
     * 配置在构造期就被 require，损坏的 config/local.php 会让异常发生在 run() 之前；
     * 那一刻容器与钩子总线都还不存在，订阅者不可能存在 —— 只能走最后一道兜底。
     */
    public static function serve(string $root): void
    {
        try {
            (new self($root))->run();
        } catch (\Throwable $e) {
            self::bareError($e);
        }
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
                self::bareError($e);
                return;
            }
            try {
                if ($response->body() === '') $response = $this->renderStage($response, $request);
            } catch (\Throwable $e) {
                self::bareError($e);
                return;
            }
            $response->send();
        }
    }

    /** 最后一道兜底：不套主题、不猜语言、不输出堆栈。 */
    private static function bareError(?\Throwable $e = null): void
    {
        if ($e !== null) {
            // 核心不写日志文件；交给 SAPI 日志，避免故障被边界吞掉后彻底失声。
            error_log('wx: unhandled: ' . $e::class . ': ' . $e->getMessage());
        }
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
