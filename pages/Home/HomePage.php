<?php
declare(strict_types=1);
namespace Wx\Pages\Home;

use Wx\Contracts\ContentRepositoryInterface;
use Wx\Contracts\PackageInterface;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;

/**
 * 首页也是一个独立页包：核心没有内置任何默认路由或默认页面。
 * 文章标题来自内容系统层的 ContentRepositoryInterface（MySQL 或回退适配器），
 * 首页自身不持有任何内容数据，也不认识 SQL。
 */
final class HomePage implements PackageInterface
{
    public function slug(): string { return 'home'; }

    public function register(Kernel $kernel): void
    {
        $kernel->hooks()->on('route.resolve', static function (Request $request, Response $response, Kernel $k): void {
            if ($request->path() !== '/') return;

            $titles = ['第一篇文章', '第二篇文章'];
            if ($k->container()->has(ContentRepositoryInterface::class)) {
                $repo  = $k->container()->resolve(ContentRepositoryInterface::class);
                $posts = $repo->list(['limit' => 10])['items'];
                if ($posts !== []) {
                    $titles = array_map(static fn(array $post): string => (string) $post['title'], $posts);
                }
            }

            $response->setPayload([
                'title' => '站点首页',
                'items' => $titles,
                'page'  => 'home',
            ]);
        }, 10);
    }
}
