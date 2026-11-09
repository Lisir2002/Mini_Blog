<?php
declare(strict_types=1);
namespace Wx\Core\Http;

final class Request
{
    public function __construct(
        private string $method = 'GET',
        private string $path = '/',
        private array $query = [],
        private array $headers = [],
        private array $body = [],
        private string $rawBody = '',
        private ?string $clientIp = null
    ) {}

    public static function fromGlobals(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
        return new self(
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            '/' . trim($path, '/'),
            $_GET,
            function_exists('getallheaders') ? (getallheaders() ?: []) : [],
            $_POST,
            (string) file_get_contents('php://input'),
            isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null
        );
    }

    public function method(): string { return $this->method; }
    public function path(): string { return $this->path; }
    public function query(): array { return $this->query; }
    public function headers(): array { return $this->headers; }
    public function body(): array { return $this->body; }
    public function rawBody(): string { return $this->rawBody; }
    public function clientIp(): ?string { return $this->clientIp; }

    /** CLI / 本机环回视为受信来源，用于「写接口默认不暴露公网」的边界判定。 */
    public function isLocal(): bool
    {
        return $this->clientIp === null || $this->clientIp === ''
            || in_array($this->clientIp, ['127.0.0.1', '::1'], true);
    }

    /** @return array<string, mixed> 解析 JSON 请求体；非法 JSON 返回空数组 */
    public function json(): array
    {
        if ($this->rawBody === '') return [];
        $decoded = json_decode($this->rawBody, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function header(string $key): ?string
    {
        foreach ($this->headers as $name => $value) {
            if (strcasecmp((string) $name, $key) === 0) return (string) $value;
        }
        return null;
    }
}
