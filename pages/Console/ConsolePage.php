<?php
declare(strict_types=1);
namespace Wx\Pages\Console;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Contracts\PackageInterface;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;
use Wx\Systems\State\StateFactory;

/**
 * 站点控制台 —— 只负责「声明路由 + 产出结构化数据」。
 *
 * 关键边界：后台不自带布局、模板、CSS 或 JS。它请求 admin 布局槽位，
 * 由主题包决定后台长什么样；主题没有 admin 布局时，后台退化为纯数据服务。
 * 卸载本目录后，核心与其余系统层零改动（只是没有后台数据）。
 */
final class ConsolePage implements PackageInterface
{
    public function slug(): string { return 'console'; }

    public function register(Kernel $kernel): void
    {
        $kernel->hooks()->on('route.resolve', static function (Request $request, Response $response, Kernel $k): void {
            if ($request->path() !== '/console') return;

            $apiCount = $k->container()->has(ApiRegistryInterface::class)
                ? count($k->container()->resolve(ApiRegistryInterface::class)->endpoints())
                : 0;

            $response->setPayload([
                'title'      => '站点控制台',
                'page'       => 'console',
                'api_prefix' => (string) $k->config()->get('api.prefix', '/api/v1'),
                'state'      => $k->container()->has(StateFactory::class)
                    ? $k->container()->resolve(StateFactory::class)->pending('state.pending', ['text' => [
                        'title'   => '控制台已就绪',
                        'message' => '页面数据由核心产出；调用受保护接口需要 admin 令牌。',
                        'hint'    => '在下方填入令牌后点「刷新」，页面不会替你保存它。',
                    ]])->toArray()
                    : null,
                'blocks'     => [
                    ['type' => 'pre', 'title' => '环境状态', 'id' => 'health', 'value' => '读取中…'],
                    ['type' => 'text', 'title' => '受保护数据',
                     'value' => '装卸单元、日志等接口需要 admin 令牌。请用 POST /api/v1/auth/token 签发。'],
                    ['type' => 'form', 'title' => '令牌', 'fields' => [
                        ['tag' => 'input', 'id' => 'token', 'type' => 'password', 'placeholder' => 'Bearer 令牌'],
                        ['tag' => 'button', 'id' => 'refresh', 'label' => '刷新'],
                    ]],
                    ['type' => 'pre', 'title' => '受保护接口结果', 'id' => 'protected', 'value' => '尚未提供令牌'],
                    ['type' => 'list', 'title' => '文章列表', 'id' => 'posts', 'items' => ['读取中…']],
                    ['type' => 'text', 'title' => '接口清单',
                     'value' => '当前登记 ' . $apiCount . ' 个规范化接口；每接口一份操作文档。'],
                ],
            ]);
        }, 10);
    }
}
