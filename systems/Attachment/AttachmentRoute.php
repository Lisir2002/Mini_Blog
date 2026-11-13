<?php
declare(strict_types=1);
namespace Wx\Systems\Attachment;

use InvalidArgumentException;
use Throwable;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;
use Wx\Systems\Api\Envelope;
use Wx\Systems\Api\RequestGuard;

/**
 * 附件二进制通道 —— 唯一不经 JSON 信封的出口，因为文件本身就是响应体。
 *
 * 只做 HTTP 适配：认领路径、复用 RequestGuard（限流 / 写边界 / 鉴权 / 参数校验）、
 * 调用同一个 operation 执行者、把结果写成 JSON 或字节流。
 * 不给文件单开一套守卫，也不让「二进制」成为绕过权限的旁路。
 */
final class AttachmentRoute
{
    private const UPLOAD = '/attach/upload';
    private const DOWNLOAD = '/attach/download';

    public function __construct(private Kernel $kernel) {}

    /** @return bool 是否认领了该请求 */
    public function handle(Request $request, Response $response): bool
    {
        $path = $request->path();
        // /attach/ 命名空间归附件系统所有：未知子路径也由本系统以 4xx 信封认领，
        // 不让核心的「未匹配门禁」把它误判成「页面不存在」的 Web 404。
        $inNamespace = $path === self::UPLOAD || $path === self::DOWNLOAD
            || str_starts_with($path, '/attach/');
        if (!$inNamespace) return false;

        $container = $this->kernel->container();
        $registry = $container->has(ApiRegistryInterface::class)
            ? $container->resolve(ApiRegistryInterface::class) : null;
        $requestId = $request->header('X-Request-Id') ?? bin2hex(random_bytes(8));
        $endpoint = $registry?->match($request->method(), $path);
        if ($registry === null || $endpoint === null) {
            $this->fail($response, 40501, $requestId);
            return true;
        }

        $check = (new RequestGuard($this->kernel))->check($request, $endpoint, $requestId);
        if ($check['reject']) {
            $this->json($response, (int) $check['status'], (array) $check['body']);
            return true;
        }
        foreach ((array) ($check['headers'] ?? []) as $key => $value) {
            $response->setHeader((string) $key, (string) $value);
        }

        $input = (array) $check['input'];
        try {
            if ($path === self::UPLOAD) {
                $input['_content'] = $request->rawBody();
                $data = $registry->execute('attachments.upload', $input);
                $this->json($response, 200, Envelope::ok($data, $requestId,
                    ['api' => (string) $endpoint['id'], 'version' => 'v1', 'system' => 'attachment']));
                return true;
            }
            $this->binary($response, (array) $registry->execute('attachments.download', $input));
        } catch (Throwable $e) {
            $this->fail($response, $this->codeOf($e), $requestId);
        }
        return true;
    }

    /** @param array<string, mixed> $data */
    private function binary(Response $response, array $data): void
    {
        $name = (string) ($data['name'] ?? 'file');
        $size = array_key_exists('path', $data)
            ? (int) ($data['size'] ?? 0)
            : strlen((string) ($data['bytes'] ?? ''));

        $response->setStatus(200)
            ->setLayout('binary')
            ->setHeader('Content-Type', (string) ($data['mime'] ?? 'application/octet-stream'))
            ->setHeader('Content-Length', (string) $size)
            ->setHeader('Content-Disposition',
                (($data['inline'] ?? false) ? 'inline' : 'attachment')
                . '; filename="' . self::asciiName($name) . '"; filename*=UTF-8\'\'' . rawurlencode($name))
            // 浏览器不得嗅探成 HTML / JS；即使内容被调包也无法在本域执行。
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->setHeader('Cache-Control', ((string) ($data['visibility'] ?? 'public')) === 'public'
                ? 'public, max-age=3600' : 'private, no-store');

        if (array_key_exists('path', $data)) {
            $path = (string) $data['path'];
            // 必须是 callable：send() 到输出时才创建生成器，避免提前打开文件句柄。
            $response->setStream(static fn(): \Generator => self::chunks($path));
            return;
        }
        $response->setBody((string) ($data['bytes'] ?? ''));
    }

    /**
     * 分块读取：一次只驻留一块，内存峰值与文件大小无关。
     * 文件在上传后不会再被改写（内容寻址），因此下载期间无需加锁。
     *
     * @return \Generator<int, string>
     */
    private static function chunks(string $path): \Generator
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) return;
        try {
            while (!feof($fh)) {
                $chunk = fread($fh, 65536);
                if ($chunk === false || $chunk === '') break;
                yield $chunk;
            }
        } finally {
            fclose($fh);
        }
    }

    /** 与 ApiDispatcher 完全一致的错误翻译：未登记的码一律 50001，不把内部数字变成对外契约。 */
    private function codeOf(Throwable $e): int
    {
        return match (true) {
            isset($e->apiCode) && is_int($e->apiCode) => $e->apiCode,
            $e instanceof InvalidArgumentException => 42201,
            isset(Envelope::TABLE[$e->getCode()]) => (int) $e->getCode(),
            default => 50001,
        };
    }

    /** ASCII 回退名：非 ASCII 与引号一律换成下划线，避免 Content-Disposition 头被注入或截断。 */
    private static function asciiName(string $name): string
    {
        $ascii = (string) preg_replace('/[^\x20-\x7E]/', '_', $name);
        return str_replace(['"', '\\', ';'], '_', $ascii) ?: 'file';
    }

    private function fail(Response $response, int $code, string $requestId): void
    {
        $this->json($response, Envelope::statusOf($code), Envelope::error($code, $requestId));
    }

    /** @param array<string, mixed> $body */
    private function json(Response $response, int $status, array $body): void
    {
        // 二进制通道的响应永远不是页面：即使失败返回 JSON，也必须绕开主题渲染。
        $response->setStatus($status)->setLayout('binary')
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setBody(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
    }
}
