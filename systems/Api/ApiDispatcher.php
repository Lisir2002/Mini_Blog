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

    /** @return array{status: int, body: array<string, mixed>, headers: array<string, string>} */
    public function dispatch(Request $request): array
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
        if ($useIdem) {
            $cached = $this->idempotency->get($idemKey);
            if ($cached !== null) {
                if (($cached['fingerprint'] ?? '') !== $fingerprint) {
                    return $this->envelope(409, Envelope::error(40902, $requestId), $headers);
                }
                $headers['Idempotency-Replayed'] = 'true';
                return $this->envelope((int) $cached['status'], (array) $cached['body'], $headers);
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

        if ($useIdem && !in_array((int) ($result['body']['code'] ?? 0), [50001, 50101], true)) {
            $this->idempotency->put($idemKey, $fingerprint, $result['status'], $result['body']);
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
