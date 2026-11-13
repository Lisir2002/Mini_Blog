<?php
declare(strict_types=1);
namespace Wx\Core\Http;

final class Request
{
    private ?array $jsonCache = null;
    private ?string $jsonError = null;
    private bool $jsonParsed = false;

    public function __construct(
        private string $method = 'GET',
        private string $path = '/',
        private array $query = [],
        private array $headers = [],
        private array $body = [],
        private string $rawBody = '',
        private ?string $clientIp = null,
        private array $files = []
    ) {
        // HTTP 头名大小写不敏感：构造时一次性归一成小写键，header() 查找 O(1)。
        // 核心不产出头，也不依赖原始大小写；小写键是合法的标准形态。
        $norm = [];
        foreach ($headers as $k => $v) {
            $norm[strtolower((string) $k)] = $v;
        }
        $this->headers = $norm;
    }

    public static function fromGlobals(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
        // multipart/form-data 时 PHP 明确禁止读 php://input（流已被解析器消费），
        // 正文只存在于 $_POST / $_FILES；这里按 Content-Type 跳过，避免读到空串
        // 让下游误判「请求体为空」。
        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        $raw = str_starts_with($contentType, 'multipart/form-data')
            ? '' : (string) file_get_contents('php://input');
        return new self(
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            '/' . trim($path, '/'),
            $_GET,
            function_exists('getallheaders') ? (getallheaders() ?: []) : [],
            $_POST,
            $raw,
            isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null,
            $_FILES
        );
    }

    public function method(): string { return $this->method; }
    public function path(): string { return $this->path; }
    public function query(): array { return $this->query; }
    public function headers(): array { return $this->headers; }
    public function body(): array { return $this->body; }
    public function rawBody(): string { return $this->rawBody; }
    public function clientIp(): ?string { return $this->clientIp; }

    /** @return array<string, mixed> 原始 $_FILES 结构，键为表单字段名 */
    public function files(): array { return $this->files; }

    /**
     * 取一个已上传文件的字节与原始文件名；不存在或 PHP 报错时返回 null。
     * tmp_name 的字节在这里读进内存：附件守卫有 8MB 上限，
     * 超限文件在守卫处被拒绝，页面不自行截断或改写。
     *
     * @return array{name: string, bytes: string, size: int}|null
     */
    public function file(string $key): ?array
    {
        $f = $this->files[$key] ?? null;
        if (!is_array($f)) return null;
        if ((int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
        $tmp = (string) ($f['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) return null;
        $bytes = (string) file_get_contents($tmp);
        return ['name' => (string) ($f['name'] ?? ''), 'bytes' => $bytes, 'size' => strlen($bytes)];
    }

    /** CLI / 本机环回视为受信来源，用于「写接口默认不暴露公网」的边界判定。 */
    public function isLocal(): bool
    {
        return $this->clientIp === null || $this->clientIp === ''
            || in_array($this->clientIp, ['127.0.0.1', '::1'], true);
    }

    /**
     * 解析 JSON 请求体，并区分三类结果：
     *  - 空 body → 空数组且 jsonError() 为 null（视为「无负载」，不是错误）；
     *  - 合法 JSON 数组 → 解码结果；
     *  - 非法 JSON 或顶层非数组（对象 / 标量）→ 空数组且 jsonError() 给出原因。
     * 此前 json() 把「非法 JSON」与「合法 null」都返回 []，畸形 API 请求体被静默当成空对象，
     * 调用方 array_merge 时错误被吞掉。现由 jsonError() / jsonValid() 显式暴露。
     * @return array<string, mixed>
     */
    public function json(): array
    {
        if ($this->jsonParsed) {
            return $this->jsonCache ?? [];
        }
        $this->jsonParsed = true;
        if ($this->rawBody === '') {
            $this->jsonCache = [];
            return [];
        }
        $decoded = json_decode($this->rawBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->jsonError = 'JSON 解析失败：' . json_last_error_msg();
            $this->jsonCache = [];
            return [];
        }
        if (!is_array($decoded)) {
            $this->jsonError = 'JSON 顶层必须是对象或数组';
            $this->jsonCache = [];
            return [];
        }
        $this->jsonCache = $decoded;
        return $decoded;
    }

    /** 最近一次 json() 的解析错误；无错（含空 body）返回 null。 */
    public function jsonError(): ?string
    {
        return $this->jsonError;
    }

    /** json() 是否成功解析出合法数组（空 body 视为成功）。 */
    public function jsonValid(): bool
    {
        return $this->jsonError === null;
    }

    public function header(string $key): ?string
    {
        return $this->headers[strtolower($key)] ?? null;
    }
}
