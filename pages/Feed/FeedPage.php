<?php
declare(strict_types=1);
namespace Wx\Pages\Feed;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Contracts\PackageInterface;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;

/**
 * 订阅与收录 —— /feed、/sitemap.xml、/robots.txt。
 *
 * 这是「非 HTML 响应」的第一条明确路径：页面声明响应类型并直接产出协议文档，
 * 布局槽位用 raw（主题没有该槽位，渲染器原样放行）。这样既不需要给主题
 * 增加 XML 渲染能力，也不会出现「主题把 RSS 包成 HTML」的荒唐结果。
 *
 * 三条纪律：
 *  1. Content-Type 必须显式声明：发现格式靠它，不靠猜；
 *  2. 取数失败返回 503 + 纯文本原因，绝不输出一份空 feed ——
 *     空 feed 会被阅读器缓存成「这个站没有文章」；
 *  3. robots.txt 指向本站的 sitemap 绝对地址，且 Disallow 后台与安装页。
 */
final class FeedPage implements PackageInterface
{
    private const FEED = '/feed';
    private const SITEMAP = '/sitemap.xml';
    private const ROBOTS = '/robots.txt';

    public function slug(): string { return 'feed'; }

    public function register(Kernel $kernel): void
    {
        $kernel->hooks()->on('route.resolve', function (Request $request, Response $response) use ($kernel): void {
            $path = $request->path();
            if (!in_array($path, [self::FEED, self::SITEMAP, self::ROBOTS], true)) return;

            $base = $this->base($kernel, $request);
            if ($path === self::ROBOTS) {
                $this->plain($response, 'text/plain; charset=utf-8', FeedSerialize::robots($base));
                return;
            }

            $registry = $this->registry($kernel);
            if ($registry === null) {
                $this->plain($response, 'text/plain; charset=utf-8',
                    '订阅源当前不可用：接口清单未装配。这不是「本站没有文章」。', 503);
                return;
            }

            try {
                $query = new FeedQuery($registry);
                if ($path === self::FEED) {
                    $this->plain($response, 'application/rss+xml; charset=utf-8',
                        FeedSerialize::rss($query->latest(), $base, $this->siteName($kernel)));
                } else {
                    $this->plain($response, 'application/xml; charset=utf-8',
                        FeedSerialize::sitemap($query->slugs(), $base));
                }
            } catch (\Throwable $e) {
                $this->plain($response, 'text/plain; charset=utf-8',
                    '订阅源生成失败：' . $e->getMessage() . '。这不是「本站没有文章」。', 503);
            }
        }, 10);
    }

    private function plain(Response $response, string $type, string $body, int $status = 200): void
    {
        // layout=raw：主题没有该槽位，渲染器原样放行 —— 协议文档不该经过 HTML 模板。
        $response->setStatus($status)->setLayout('raw')
            ->setHeader('Content-Type', $type)->setBody($body);
    }

    /** 绝对地址的来源：优先 config.site.url，其次按请求头推断（反向代理看 X-Forwarded-Proto）。 */
    private function base(Kernel $kernel, Request $request): string
    {
        $configured = trim((string) $kernel->config()->get('site.url', ''));
        if ($configured !== '') return rtrim($configured, '/');

        $host = trim((string) ($request->header('Host') ?? 'localhost'));
        $proto = strtolower(trim((string) ($request->header('X-Forwarded-Proto') ?? 'http')));
        return ($proto === 'https' ? 'https' : 'http') . '://' . ($host === '' ? 'localhost' : $host);
    }

    private function siteName(Kernel $kernel): string
    {
        $name = trim((string) $kernel->config()->get('site.name', ''));
        return $name === '' ? '站点' : $name;
    }

    private function registry(Kernel $kernel): ?ApiRegistryInterface
    {
        return $kernel->container()->has(ApiRegistryInterface::class)
            ? $kernel->container()->resolve(ApiRegistryInterface::class) : null;
    }
}
