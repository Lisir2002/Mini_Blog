<?php
declare(strict_types=1);
namespace Wx\Pages\Links;

use Wx\Contracts\PackageInterface;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;

/** 独立页示例：自带路由与数据，复用 site 布局；外观完全跟随当前主题。 */
final class LinksPage implements PackageInterface
{
    public function slug(): string { return 'links'; }

    public function register(Kernel $kernel): void
    {
        $kernel->hooks()->on('route.resolve', static function (Request $request, Response $response): void {
            if ($request->path() === '/links') {
                $response->setPayload([
                    'title' => '友情链接',
                    'items' => ['独立页系统承载，无需内置路由', '可自带模板与资源，也可复用主题'],
                    'page'  => 'links',
                ]);
            }
        }, 10);
    }
}
