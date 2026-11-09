<?php
declare(strict_types=1);
namespace Wx\Systems\State;

use Throwable;

/**
 * 状态工厂 —— 把「相位 + 文案键」组装成一个合法的 State，并负责两种外部输入的翻译：
 *   Throwable → 失败态；API 统一信封 → 任意状态。
 *
 * 这里刻意不把异常原文塞进 message：SQL、路径、令牌片段不该出现在用户界面。
 * 原始信息留在日志里，界面只给稳定的中文说明与 trace_id，靠编号关联现场。
 */
final class StateFactory
{
    public function __construct(private StateCopy $copy) {}

    /** @param array<string, mixed> $o */
    public function pending(string $key = 'state.pending', array $o = []): State
    {
        return $this->compose(State::PENDING, $key, $o);
    }

    /** @param array<string, mixed> $o */
    public function running(string $key = 'state.running', array $o = []): State
    {
        return $this->compose(State::RUNNING, $key, $o);
    }

    /** @param array<string, mixed> $o */
    public function waiting(string $key = 'state.waiting.system', array $o = []): State
    {
        return $this->compose(State::WAITING, $key, $o, 'system');
    }

    /** @param array<string, mixed> $o */
    public function needsInput(string $key = 'state.waiting.user', array $o = []): State
    {
        return $this->compose(State::WAITING, $key, $o, 'user');
    }

    /** @param array<string, mixed> $o */
    public function done(string $key = 'state.done', array $o = []): State
    {
        return $this->compose(State::DONE, $key, $o);
    }

    /** @param array<string, mixed> $o */
    public function failed(int $code, string $key = 'state.error.internal', array $o = []): State
    {
        $o['retryable'] = (bool) ($o['retryable'] ?? ($code >= 50000 || $code === 42901));
        return $this->compose(State::FAILED, $key, $o, null, $code);
    }

    public function fromThrowable(Throwable $e, ?string $traceId = null): State
    {
        $code = (int) $e->getCode();
        if ($code < 40000 || $code > 59999) $code = 50001;
        $o = [];
        if ($traceId !== null && $traceId !== '') $o['trace_id'] = $traceId;
        return $this->failed($code, self::keyOf($code), $o);
    }

    /** @param array<string, mixed> $body */
    public function fromEnvelope(array $body): State
    {
        $code = (int) ($body['code'] ?? 50001);
        $trace = (string) ($body['request_id'] ?? '');
        $detail = (string) ($body['detail'] ?? '');
        if ($detail === '') {
            $errors = (array) ($body['errors'] ?? []);
            $first = (array) ($errors[0] ?? []);
            $detail = (string) ($first['message'] ?? '');
        }
        if ($code === 0) {
            return $this->done('state.done', $detail === '' ? [] : ['message' => $detail]);
        }
        $o = $detail === '' ? [] : ['message' => $detail];
        if ($trace !== '') $o['trace_id'] = $trace;
        return $this->failed($code, self::keyOf($code), $o);
    }

    public function copy(): StateCopy { return $this->copy; }

    /** @param array<string, mixed> $o */
    private function compose(string $phase, string $key, array $o, ?string $heldBy = null, int $code = 0): State
    {
        $text = $o['text'] ?? [];
        unset($o['text']);
        return State::create(array_replace(
            ['phase' => $phase, 'held_by' => $heldBy, 'code' => $code, 'scope' => 'action'],
            $this->copy->resolve($key, is_array($text) ? $text : []),
            $o
        ));
    }

    private static function keyOf(int $code): string
    {
        return match (intdiv($code, 100)) {
            401 => 'state.error.auth',
            403 => 'state.error.permission',
            404 => 'state.error.not_found',
            409 => 'state.error.conflict',
            422 => 'state.error.validation',
            429 => 'state.error.rate_limit',
            500, 501, 502, 503, 504 => 'state.error.internal',
            default => 'state.error.internal',
        };
    }
}
