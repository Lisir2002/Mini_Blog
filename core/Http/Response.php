<?php
declare(strict_types=1);
namespace Wx\Core\Http;

/**
 * 响应对象。默认 body 为空字符串，且默认 Content-Type 不是 text/html。
 * 核心从不往 body 里写标签；只有主题渲染器才有权写入 HTML。
 */
final class Response
{
    private int $status = 200;
    /** @var array<string, string> */
    private array $headers = ['Content-Type' => 'text/plain; charset=utf-8'];
    private string $body = '';
    private mixed $payload = null;
    /** 布局槽位名：只是意图字符串，核心不解释其含义、不据此产出任何标签。 */
    private string $layout = 'site';

    public function setStatus(int $status): static { $this->status = $status; return $this; }
    public function setHeader(string $key, string $value): static { $this->headers[$key] = $value; return $this; }
    public function setBody(string $body): static { $this->body = $body; return $this; }
    public function setPayload(mixed $payload): static { $this->payload = $payload; return $this; }
    public function setLayout(string $layout): static { $this->layout = $layout; return $this; }

    public function status(): int { return $this->status; }
    public function headers(): array { return $this->headers; }
    public function contentType(): string { return $this->headers['Content-Type'] ?? ''; }
    public function body(): string { return $this->body; }
    public function payload(): mixed { return $this->payload; }
    public function layout(): string { return $this->layout; }

    /** Headless 输出：把结构化数据序列化，绝不附带任何表现层内容。 */
    public function asData(): static
    {
        $this->setHeader('Content-Type', 'application/json; charset=utf-8');
        $json = json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $this->setBody($json === false ? '' : $json);
        return $this;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $key => $value) {
            header($key . ': ' . $value);
        }
        echo $this->body;
    }
}
