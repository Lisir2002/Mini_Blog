<?php
declare(strict_types=1);
namespace Wx\Systems\Metric;

use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;
use Wx\Systems\Data\QueryProfilerInterface;

/**
 * 自动埋点 —— 统计系统对业务代码的全部侵入都在这里，且只有订阅，没有修改。
 *
 * 四条采集通道（可用 metric.channels 单独关闭）：
 *   http  内核响应：请求量、状态码、5xx/4xx、端到端耗时
 *   api   分发器执行完：operation 调用量、业务码、执行耗时
 *   db    请求结束：本次请求的 SQL 条数与总耗时（来自既有 QueryProfiler）
 *   business 既有业务事件：文章 / 评论 / 附件 / 经验
 *
 * 为什么挂在钩子上而不是改业务代码：埋点是旁路，业务层不需要知道统计层存在。
 * 关掉统计系统时，业务语义必须逐字不变 —— 这是「可卸载」对可观测性的要求。
 */
final class MetricInstrumentation
{
    public function __construct(private MetricRecorder $recorder, private Kernel $kernel) {}

    public function attach(): void
    {
        $channels = (array) $this->kernel->config()->get('metric.channels', []);
        $on = static fn(string $name): bool => ($channels[$name] ?? true) === true;

        if ($on('http')) $this->http();
        if ($on('api'))  $this->api();
        if ($on('db'))   $this->db();
        if ($on('business')) $this->business();

        // 缓冲统一在请求结束落库：一次事务，失败也不影响已经发出的响应。
        $this->kernel->hooks()->on('kernel.shutdown', function (): void {
            $this->recorder->flush();
        }, 999);
    }

    private function http(): void
    {
        $recorder = $this->recorder;
        // 用 shutdown 而不是 response：这时主题渲染已经结束，耗时才是端到端的；
        // 时长由内核传入，避免在 CLI / 测试里把进程启动时间当成请求耗时。
        $this->kernel->hooks()->on('kernel.shutdown', static function (
            Request $request, Response $response, Kernel $kernel, float $durationMs = 0.0
        ) use ($recorder): void {
            $method = $request->method();
            $status = (string) $response->status();
            $recorder->counter('http_requests', ['method' => $method, 'status' => $status]);
            if ((int) $status >= 500) $recorder->counter('http_errors', ['method' => $method]);
            elseif ((int) $status >= 400) $recorder->counter('http_client_errors', ['method' => $method]);
            $recorder->observe('http_duration_ms', $durationMs, ['method' => $method]);
        }, 20);

        // 未捕获异常不会走到 kernel.shutdown：必须单独记一次，否则最严重的错误反而统计不到。
        $this->kernel->hooks()->filter('kernel.exception', static function ($response, \Throwable $e, Request $request) use ($recorder) {
            $recorder->counter('http_requests', ['method' => $request->method(), 'status' => '500']);
            $recorder->counter('http_errors', ['method' => $request->method()]);
            // 异常路径没有 shutdown 出口，必须就地落库；否则「最该被看见的错误」恰好留在内存里。
            $recorder->flush();
            return $response;
        }, 10);
    }

    private function api(): void
    {
        $recorder = $this->recorder;
        $this->kernel->hooks()->on('api.executed', static function (
            string $operation, int $code, int $status, float $durationMs
        ) use ($recorder): void {
            if ($operation === '') return;
            $recorder->counter('api_calls', ['operation' => $operation, 'code' => (string) $code]);
            $recorder->observe('api_duration_ms', $durationMs, ['operation' => $operation]);
        }, 10);
    }

    private function db(): void
    {
        $kernel = $this->kernel;
        $recorder = $this->recorder;
        $this->kernel->hooks()->on('kernel.shutdown', static function () use ($kernel, $recorder): void {
            if (!$kernel->container()->has(QueryProfilerInterface::class)) return;
            /** @var QueryProfilerInterface $profiler */
            $profiler = $kernel->container()->resolve(QueryProfilerInterface::class);
            $recorder->observe('db_queries', (float) $profiler->count());
            $recorder->observe('db_duration_ms', $profiler->totalMs());
        }, 900);
    }

    private function business(): void
    {
        $recorder = $this->recorder;
        $hooks = $this->kernel->hooks();

        $hooks->on('content.created', static function () use ($recorder): void {
            $recorder->counter('content_created');
        }, 10);
        $hooks->on('comment.created', static function (mixed $id, array $row = []) use ($recorder): void {
            $recorder->counter('comment_created', ['status' => (string) ($row['status'] ?? '')]);
        }, 10);
        $hooks->on('comment.deleted', static function () use ($recorder): void {
            $recorder->counter('comment_deleted');
        }, 10);
        $hooks->on('comment.moderated', static function (mixed $id, string $to = '') use ($recorder): void {
            $recorder->counter('comment_moderated', ['to' => $to]);
        }, 10);
        $hooks->on('attachment.uploaded', static function () use ($recorder): void {
            $recorder->counter('attachment_uploaded');
        }, 10);
        $hooks->on('member.xp.awarded', static function (array $event = []) use ($recorder): void {
            $recorder->counter('member_xp_awarded', ['action' => (string) ($event['action'] ?? '')]);
        }, 10);
    }
}
