<?php
declare(strict_types=1);
namespace Wx\Plugins\Hello;

use Wx\Contracts\PackageInterface;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;

/** 示例插件：只通过钩子改写数据，不碰核心、不碰模板文件。 */
final class HelloPlugin implements PackageInterface
{
    public function slug(): string { return 'hello'; }

    public function register(Kernel $kernel): void
    {
        $kernel->hooks()->filter('kernel.response.filter', static function (Response $response): Response {
            $payload = (array) $response->payload();
            $payload['title'] = $payload['title'] ?? '万象 · 原型站点';
            $payload['items'] = array_merge(
                (array) ($payload['items'] ?? []),
                ['这一行由插件系统通过钩子注入']
            );
            return $response->setPayload($payload);
        }, 20);
    }
}
