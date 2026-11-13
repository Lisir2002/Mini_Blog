<?php
declare(strict_types=1);
namespace Wx\Core;

use Wx\Core\Http\Request;
use Wx\Core\Http\Response;

/**
 * 极简路由器。核心不内置任何路由、不内置任何页面。
 * 谁能处理这个请求，完全由系统层 / 独立页 / 插件通过 route.resolve 声明。
 *
 * 核心只负责「有没有人认领」这一件与业务无关的事：route.access 做拒绝短路，
 * route.resolve 广播给订阅者，route.response 收束响应；若最终仍是一份「无人产出过内容」
 * 的 200 空壳，核心发出 route.notFound 钩子并把状态锁死在 404（fail-closed）。
 * 核心不认识任何路由、页面类型或路径前缀——它只认「响应是否被写过」。
 */
final class Router
{
    public function resolve(Request $request, Kernel $kernel): Response
    {
        $response = new Response();

        // 可编程访问裁决点：任何系统层都能订阅，核心只认「HTTP 状态是否已表达拒绝」，
        // 不认任何身份、权限名或页面类型，也不产出任何呈现内容。
        // 拒绝时短路，页面 / 业务回调不会被执行（fail-closed 的结构性保证）。
        $response = $kernel->hooks()->apply('route.access', $response, $request, $kernel);
        if ($response->status() >= 400) return $response;

        $kernel->hooks()->emit('route.resolve', $request, $response, $kernel);
        $response = $kernel->hooks()->apply('route.response', $response, $request, $kernel);

        // 未匹配门禁（fail-closed，知识无关）：核心只认「响应是否被人产出过内容」，
        // 不认任何路由、页面类型或路径前缀——因此与页面层 / API 层 / 附件层知识彻底解耦。
        // 没有任何系统层 / 独立页 / 插件往响应里写过内容（仍是 200 空壳）时，核心认定
        // 「无人认领」，发出 route.notFound 钩子供页面层 / 主题填充友好文案（deny 槽位）；
        // 若无人订阅，核心兜底置 404 + deny，绝不把「200 空壳」当成一次成功响应透传出去
        // （此前的静默成功会让拼错的地址看起来像存在的页面，还会被搜索引擎收录）。
        if ($response->status() === 200 && $response->payload() === null && $response->body() === '' && !$response->isStreamed()) {
            $response = $kernel->hooks()->apply('route.notFound', $response, $request, $kernel);
            // fail-closed 兜底：钩子跑完仍停在 200，说明无人真正认领（订阅者可能误写回 200
            // 或干脆缺席），核心必须强制 404——这是最后一道，不可被后续 filter 降级为成功。
            if ($response->status() === 200) {
                $response->setStatus(404)->setLayout('deny');
            }
        }

        return $response;
    }
}
