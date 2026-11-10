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
    /** @var (callable(): iterable<string>)|null 只产出字节块，不做任何缓冲 */
    private $stream = null;
    /** 布局槽位名：只是意图字符串，核心不解释其含义、不据此产出任何标签。 */
    private string $layout = 'site';

    public function setStatus(int $status): static { $this->status = $status; return $this; }
    public function setHeader(string $key, string $value): static { $this->headers[$key] = $value; return $this; }
    public function setBody(string $body): static { $this->body = $body; return $this; }
    public function setPayload(mixed $payload): static { $this->payload = $payload; return $this; }
    public function setLayout(string $layout): static { $this->layout = $layout; return $this; }

    /**
     * 用块生成器替代 body：一次只持有一块，内存不随文件大小增长。
     * 一旦设置，body 置空，避免同一份字节被送两次。
     */
    public function setStream(callable $stream): static
    {
        $this->stream = $stream;
        $this->body   = '';
        return $this;
    }

    public function isStreamed(): bool { return $this->stream !== null; }

    public function status(): int { return $this->status; }
    public function headers(): array { return $this->headers; }
    public function contentType(): string { return $this->headers['Content-Type'] ?? ''; }
    public function body(): string { return $this->body; }
    public function payload(): mixed { return $this->payload; }
    public function layout(): string { return $this->layout; }

    /** Headless 输出：把结构化数据序列化，绝不附带任何表现层内容。 */
    public function asData(): static
    {
        // 流式响应已有字节来源：不能因为 body 为空就把它改写成 JSON。
        // （v0.20 修：插件向 route.response 注入 payload 后，这条兜底会把 64MB 流覆盖成一段 JSON。）
        if ($this->stream !== null) return $this;
        $this->setHeader('Content-Type', 'application/json; charset=utf-8');
        $json = json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $this->setBody($json === false ? '' : $json);
        return $this;
    }

    public function send(): void
    {
        // CLI 没有 HTTP 头；测试里也常有先行输出，此时写头只会产生警告，不影响 body。
        if (PHP_SAPI !== 'cli') {
            http_response_code($this->status);
            if (!headers_sent()) {
                foreach ($this->headers as $key => $value) {
                    header($key . ': ' . $value);
                }
            }
        }
        if ($this->stream !== null) {
            foreach (($this->stream)() as $chunk) {
                echo $chunk;
                // 只在真正的 HTTP 环境推缓冲；CLI（含测试）里 ob_flush 会冲出调用方的缓冲链，
                // 把已经累积的输出提前送到 stdout，破坏调用方自己的捕获范围。
                if (PHP_SAPI !== 'cli') {
                    if (ob_get_level() > 0) @ob_flush();
                    flush();
                }
            }
            return;
        }
        echo $this->body;
    }
}
