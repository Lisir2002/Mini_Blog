<?php
declare(strict_types=1);
namespace Wx\Plugins\Performance;

use Wx\Core\Http\Request;
use Wx\Core\Http\Response;

/**
 * 响应传输经济 —— 只做无状态的协商缓存与编码，不落盘、不跨请求共享、不认识业务。
 *
 * 两条硬边界：
 *   1. 带身份（Authorization / Cookie）或已经声明 no-store / Set-Cookie 的响应一律不碰；
 *   2. ETag 把「编码变体」算进去（-i / -g），保证一个 ETag 只对应一份字节，
 *      且 304 时不会残留 Content-Encoding —— 无状态协商不需要 removeHeader。
 */
final class ResponseEconomy
{
    public function __construct(
        private int $minBytes = 256,
        private bool $gzip = true,
        private int $maxAge = 60
    ) {}

    public function apply(Request $request, Response $response): void
    {
        if (!$this->eligible($request, $response)) return;

        $raw = $response->body();
        $useGzip = $this->wantsGzip($request, $raw);
        $etag = 'W/"' . substr(hash('sha256', $raw), 0, 16) . ($useGzip ? '-g' : '-i') . '"';

        $response->setHeader('ETag', $etag)
            ->setHeader('Cache-Control', 'public, max-age=' . $this->maxAge . ', must-revalidate')
            ->setHeader('Vary', 'Accept-Encoding');

        $inm = trim((string) $request->header('If-None-Match'));
        if ($inm === $etag || $inm === '*') {
            // 实体没变：只回状态行与协商头，body 归零。此处尚未写 Content-Encoding。
            $response->setStatus(304)
                ->setBody('')
                ->setHeader('Content-Length', '0');
            return;
        }

        if (!$useGzip) return;

        $packed = gzencode($raw, 6);
        if ($packed === false || strlen($packed) >= strlen($raw)) return;
        $response->setBody($packed)->setHeader('Content-Encoding', 'gzip');
    }

    private function eligible(Request $request, Response $response): bool
    {
        if (!in_array($request->method(), ['GET', 'HEAD'], true)) return false;
        if ($response->status() !== 200 || $response->body() === '') return false;
        if ($request->header('Authorization') !== null || $request->header('Cookie') !== null) return false;

        foreach ($response->headers() as $name => $value) {
            if (strcasecmp((string) $name, 'Set-Cookie') === 0) return false;
            if (strcasecmp((string) $name, 'Cache-Control') === 0
                && str_contains(strtolower((string) $value), 'no-store')) return false;
        }
        return true;
    }

    private function wantsGzip(Request $request, string $raw): bool
    {
        if (!$this->gzip || strlen($raw) < $this->minBytes || !function_exists('gzencode')) return false;
        $accept = strtolower((string) $request->header('Accept-Encoding'));
        return $accept !== '' && str_contains($accept, 'gzip');
    }
}
