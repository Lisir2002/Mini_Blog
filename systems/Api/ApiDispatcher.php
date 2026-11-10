<?php
declare(strict_types=1);
namespace Wx\Systems\Api;

use InvalidArgumentException;
use Throwable;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;

/**
 * 请求分发编排者 —— 只负责「匹配 → 守卫 → 幂等 → 唯一执行者 → 统一信封」。
 * 限流、写边界、鉴权、参数校验已委托给 RequestGuard；幂等存储委托给 IdempotencyStore。
 * 校验失败与 429 不进入幂等缓存；5xx / 未实现不缓存，避免把故障固化。
 *
 * v0.20 幂等改为「先占位、后回填」：claim 在文件锁内完成，同键并发只有一个执行，
 * 其余得到 40905 而不是各自把业务跑一遍。执行失败释放占位，客户端可安全重试。
 */
final class ApiDispatcher
{
    private IdempotencyStore $idempotency;
    private RequestGuard $guard;

    public function __construct(private ApiRegistry $registry, private Kernel $kernel)
    {
        $this->idempotency = new IdempotencyStore(
            $kernel->root(), (int) $kernel->config()->get('api.idempotency.ttl', 3600)
        );
        $this->guard = new RequestGuard($kernel);
    }

    /**
     * 对外入口只多做一件事：把「哪个操作、什么结果、花了多久」广播出去。
     * 重新匹配一次 endpoint 是有意为之 —— 避免为了统计给每个 return 都插一段收尾代码，
     * 那种写法在新增分支时必定漏记，而漏记正是统计系统最不该犯的错。
     *
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>}
     */
    public function dispatch(Request $request): array
    {
        $started = microtime(true);
        $result  = $this->dispatchOnce($request);
        $endpoint = $this->registry->match($request->method(), $request->path());
        $this->kernel->hooks()->emit(
            'api.executed',
            (string) ($endpoint['operation'] ?? ''),
            (int) ($result['body']['code'] ?? 0),
            (int) $result['status'],
            round((microtime(true) - $started) * 1000, 3)
        );
        return $result;
    }

    /** @return array{status: int, body: array<string, mixed>, headers: array<string, string>} */
    private function dispatchOnce(Request $request): array
    {
        $requestId = $request->header('X-Request-Id') ?? bin2hex(random_bytes(8));
        $endpoint  = $this->registry->match($request->method(), $request->path());
        if ($endpoint === null) {
            $code = $this->registry->pathExists($request->path()) ? 40501 : 40401;
            return $this->envelope(Envelope::statusOf($code), Envelope::error($code, $requestId));
        }

        $g = $this->guard->check($request, $endpoint, $requestId);
        if ($g['reject']) {
            return $this->envelope($g['status'], $g['body'], $g['headers']);
        }
        $headers = $g['headers'];
        $input   = $g['input'];

        $idemKey = $request->header('Idempotency-Key');
        $useIdem = $g['is_write'] && $idemKey !== null && $idemKey !== ''
            && $this->kernel->config()->get('api.idempotency.enabled', true) === true;
        $fingerprint = $useIdem ? $this->fingerprint($request) : '';
        $claim = null;
        if ($useIdem) {
            $claim = $this->idempotency->claim($idemKey, $fingerprint);
            if ($claim['state'] === 'conflict') {
                return $this->envelope(409, Envelope::error(40902, $requestId), $headers);
            }
            if ($claim['state'] === 'inflight') {
                // 同键上一个请求还没写完：让客户端重试，而不是重复执行一次副作用。
                $headers['Retry-After'] = '1';
                return $this->envelope(409, Envelope::error(40905, $requestId), $headers);
            }
            if ($claim['state'] === 'replay') {
                $headers['Idempotency-Replayed'] = 'true';
                return $this->envelope((int) ($claim['status'] ?? 200), (array) ($claim['body'] ?? []), $headers);
            }
        }

        $meta = ['api' => $endpoint['id'], 'version' => $endpoint['version'] ?? 'v1', 'system' => $endpoint['system']];
        try {
            $data   = $this->registry->execute((string) $endpoint['operation'], $input);
            $result = $this->envelope(200, Envelope::ok($data, $requestId, $meta), $headers);
        } catch (Throwable $e) {
            $code = match (true) {
                isset($e->apiCode) && is_int($e->apiCode) => $e->apiCode,
                $e instanceof InvalidArgumentException       => 42201,
                // 领域异常用 getCode() 携带已在码表登记的业务码（版本层的 40903/40904、
                // 装卸层的 40301/40402 等）。只有登记过的码才被翻译成 HTTP，其余一律 50001，
                // 避免把未定义的数字意外变成对外契约。
                isset(Envelope::TABLE[$e->getCode()])        => (int) $e->getCode(),
                default                                      => 50001,
            };
            $detail = $this->kernel->config()->get('api.expose_errors', false) === true ? $e->getMessage() : null;
            $result = $this->envelope(
                Envelope::statusOf($code),
                Envelope::error($code, $requestId,
                    [['code' => 'operation_failed', 'message' => Envelope::TABLE[$code][1] ?? '未知错误']],
                    $detail, $meta),
                $headers
            );
        }

        if ($useIdem && ($claim['state'] ?? '') === 'claimed') {
            if (in_array((int) ($result['body']['code'] ?? 0), [50001, 50101], true)) {
                // 故障不固化：退回占位，允许客户端带同一键重试。
                $this->idempotency->release($idemKey);
            } else {
                $this->idempotency->complete($idemKey, $fingerprint, $result['status'], $result['body']);
            }
        }
        return $result;
    }

    private function fingerprint(Request $request): string
    {
        return hash('sha256', $request->method() . ' ' . $request->path() . '|' . json_encode([
            $request->query(), $request->body(), $request->json(),
        ]));
    }

    /** @param array<string, string> $headers */
    private function envelope(int $status, array $body, array $headers = []): array
    {
        return ['status' => $status, 'body' => $body, 'headers' => $headers];
    }
}
