<?php
declare(strict_types=1);
namespace Wx\Core;

use Wx\Core\Http\Request;
use Wx\Core\Http\Response;

/**
 * 极简路由器。核心不内置任何路由、不内置任何页面。
 * 谁能处理这个请求，完全由系统层 / 独立页 / 插件通过 route.resolve 声明。
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
        return $kernel->hooks()->apply('route.response', $response, $request, $kernel);
    }
}
