<?php
declare(strict_types=1);
namespace Wx\Tools\Bench;

use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Systems\Data\QueryProfilerInterface;

/**
 * 应用层 A/B —— 只跑真实 Kernel::handle()，不造数字、不模拟耗时。
 *
 * 口径刻意与真实浏览器一致：
 *   关闭组从没收到过 ETag，所以每次都是完整 200；
 *   开启组第一次拿 ETag，之后带 If-None-Match 协商。
 * 两组都从容器直接读 QueryProfiler，用来证明「开关不改变服务端计算量」。
 */
final class AppProbe
{
    /** @var list<array{path:string, query:array<string,string>}> */
    private array $routes;

    public function __construct(private string $root)
    {
        $this->routes = [
            ['path' => '/', 'query' => []],
            ['path' => '/api/v1/content/posts', 'query' => []],
            ['path' => '/api/v1/emojis', 'query' => []],
            ['path' => '/api/v1/content/post', 'query' => ['slug' => 'first-post']],
            ['path' => '/api/v1/system/info', 'query' => []],
        ];
    }

    /**
     * @param array<string, string> $canonical 关闭组各路径的原始 body，用于语义等价核对
     * @return array<string, mixed>
     */
    public function run(bool $enabled, int $rounds, array $canonical = []): array
    {
        $bytes = 0;
        $status200 = 0;
        $status304 = 0;
        $gzip = 0;
        $queries = 0;
        $mismatches = 0;
        $times = [];
        $own = [];
        $etags = [];

        foreach ($this->routes as $route) {
            $key = $route['path'] . '?' . http_build_query($route['query']);
            for ($i = 0; $i < $rounds; $i++) {
                $headers = ['Accept-Encoding' => 'gzip'];
                if ($enabled && isset($etags[$key])) {
                    $headers['If-None-Match'] = $etags[$key];
                }

                $kernel = new Kernel($this->root, ['performance' => ['enabled' => $enabled]]);
                $start = hrtime(true);
                $response = $kernel->handle(new Request('GET', $route['path'], $route['query'], $headers));
                $times[] = (hrtime(true) - $start) / 1_000_000.0;

                $container = $kernel->container();
                if ($container->has(QueryProfilerInterface::class)) {
                    $queries += $container->resolve(QueryProfilerInterface::class)->count();
                }

                $body = $response->body();
                $bytes += strlen($body);
                $heads = $response->headers();
                if ($response->status() === 304) {
                    $status304++;
                } else {
                    $status200++;
                }
                $encoded = isset($heads['Content-Encoding']) && $heads['Content-Encoding'] === 'gzip';
                if ($encoded) $gzip++;

                $plain = $encoded ? (string) gzdecode($body) : $body;
                if ($response->status() === 200) {
                    // 语义等价的口径：剔除每请求唯一的 request_id 后逐字比较。
                    // 它是链路追踪元数据而非业务数据，跨请求必然不同；不剔除会得出假失败。
                    $own[$key] = self::normalize($plain);
                    if (isset($canonical[$key]) && $canonical[$key] !== $own[$key]) $mismatches++;
                }
                if (isset($heads['ETag'])) $etags[$key] = (string) $heads['ETag'];
            }
        }

        sort($times);
        return [
            'enabled'     => $enabled,
            'requests'    => count($times),
            'bytes'       => $bytes,
            'status200'   => $status200,
            'status304'   => $status304,
            'gzip'        => $gzip,
            'queries'     => $queries,
            'mismatches'  => $mismatches,
            'medianMs'    => self::median($times),
            'p95Ms'       => self::at($times, 0.95),
            'totalMs'     => round(array_sum($times), 2),
            'canonical'   => $own,
        ];
    }

    private static function normalize(string $body): string
    {
        $data = json_decode($body, true);
        if (!is_array($data)) return $body;
        unset($data['request_id']);
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function median(array $sorted): float
    {
        return self::at($sorted, 0.5);
    }

    private static function at(array $sorted, float $q): float
    {
        if ($sorted === []) return 0.0;
        $idx = (int) min(count($sorted) - 1, max(0, floor($q * (count($sorted) - 1))));
        return round($sorted[$idx], 3);
    }
}
