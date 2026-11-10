<?php
declare(strict_types=1);
namespace Wx\Tools\Journey;

use Wx\Core\Http\Request;
use Wx\Core\Kernel;

/**
 * HTTP 探针 —— 全程走真实请求管线（路由 → 守卫 → 权限 → 执行者 → 渲染器）。
 *
 * 不直接调容器方法、不读私有状态：每个结论都来自一次 Kernel::handle() 的响应，
 * 这样「零件都对但拼起来不工作」的问题才会暴露。每步留一行纯文本证据。
 */
final class Probe
{
    private int $passed = 0;
    private int $failed = 0;
    /** @var list<string> */
    private array $lines = [];
    /** @var array<string, mixed> */
    private array $facts = [];

    /** 执行一个旅程步骤：断言失败只记 FAIL，不中断后续步骤（一次跑完收齐全部问题）。 */
    public function step(string $label, callable $fn): void
    {
        $before = $this->failed;
        try {
            $fn($this);
            if ($this->failed === $before) {
                $this->passed++;
                $this->lines[] = '  PASS  ' . $label;
            }
        } catch (\Throwable $e) {
            $this->failed++;
            $this->lines[] = '  FAIL  ' . $label . "\n         " . $e->getMessage();
        }
    }

    public function ok(bool $condition, string $message): void
    {
        if (!$condition) throw new \RuntimeException($message);
    }

    public function note(string $evidence): void
    {
        $this->lines[] = '         · ' . $evidence;
    }

    public function fact(string $key, mixed $value): void
    {
        $this->facts[$key] = $value;
    }

    /** @return array<string, mixed> */
    public function facts(): array { return $this->facts; }

    /**
     * 一次真实请求。
     * @return array{status:int, json:array, body:string, ct:string, headers:array}
     */
    public function request(Kernel $k, string $method, string $path,
                            array $query = [], array $headers = [], string $raw = '',
                            ?string $clientIp = null): array
    {
        $r = $k->handle(new Request($method, $path, $query, $headers, [], $raw, $clientIp));
        return [
            'status'  => $r->status(),
            'json'    => (array) json_decode($r->body(), true),
            'body'    => $r->body(),
            'ct'      => $r->contentType(),
            'headers' => $r->headers(),
        ];
    }

    public function get(Kernel $k, string $path, array $query = [], array $headers = [],
                        ?string $clientIp = null): array
    {
        return $this->request($k, 'GET', $path, $query, $headers, '', $clientIp);
    }

    /** @param array|string $payload 数组自动编码 JSON；字符串原样作为请求体（二进制通道） */
    public function post(Kernel $k, string $path, array|string $payload,
                         array $query = [], array $headers = []): array
    {
        $raw = is_array($payload) ? (string) json_encode($payload, JSON_UNESCAPED_UNICODE) : $payload;
        return $this->request($k, 'POST', $path, $query, $headers, $raw);
    }

    /** 断言统一信封成功，并返回 data 段。 */
    public function data(array $res, string $where): array
    {
        $this->ok(($res['json']['code'] ?? null) === 0,
            $where . ' 业务码不为 0：' . substr($res['body'], 0, 220));
        return (array) ($res['json']['data'] ?? []);
    }

    /** @return list<string> */
    public function lines(): array { return $this->lines; }
    public function passed(): int { return $this->passed; }
    public function failed(): int { return $this->failed; }
}
