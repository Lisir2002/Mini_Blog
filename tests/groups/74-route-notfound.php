<?php
declare(strict_types=1);

/**
 * 74 · 未匹配路由的显式 404 钩子（route.notFound，v0.58）
 *
 * 锁「形态 0」的核心不变量：
 *  - 核心用「响应是否被产出过内容」这一知识无关信号判定未匹配，不认识任何路由 / 页面 / 前缀；
 *  - 未匹配时发出 route.notFound 钩子，页面层填充友好文案（deny 槽位）；
 *  - 即便无人订阅 route.notFound 或订阅者把状态打回 200，核心也强制 404（fail-closed）；
 *  - API / 附件命名空间由各自子系统自认领（产出 4xx 信封 / 字节流），核心不会误判成 Web 404。
 */

use Wx\Core\Http\Request;
use Wx\Core\Kernel;

echo "\n=== 74. 未匹配路由显式 404 钩子（route.notFound）===\n";

check('D1 · 未匹配 Web 路径触发 route.notFound，且页面层仍产出结构化 not_found 载荷', function () use ($root): void {
    $k = adminKernel();
    $fired = false;
    // 最先运行的 filter：只观察钩子是否被触发，原样透传（不抢占页面层的 404 文案）。
    $k->hooks()->filter('route.notFound', static function ($resp) use (&$fired) {
        $fired = true;
        return $resp;
    }, 1);
    $r = $k->handle(new Request('GET', '/no-such-page'));
    truthy($fired, '未匹配路径应触发 route.notFound 钩子');
    truthy($r->status() === 404, '应返回 404，实际 ' . $r->status());
    $body = json_decode($r->body(), true);
    truthy(($body['page'] ?? '') === 'not_found', '404 应带结构化 not_found 载荷');
    truthy(!str_contains(strtolower($r->body()), '<html'), '无主题时 404 不应输出 HTML');
});

check('D2 · API 未注册操作不被误判为 Web 404：API 已自产 4xx 信封，route.notFound 不应触发', function () use ($root): void {
    $k = adminKernel();
    $fired = false;
    $k->hooks()->filter('route.notFound', static function ($resp) use (&$fired) {
        $fired = true;
        return $resp;
    }, 1);
    $r = $k->handle(new Request('GET', '/api/v1/does-not-exist', [], adminHeaders()));
    truthy(!$fired, 'API 未注册操作不应触发 Web 的 route.notFound（API 已自产 4xx 信封）');
    truthy($r->status() !== 200, 'API 未注册操作应返回客户端错误，实际 ' . $r->status());
});

check('D3 · 核心 fail-closed：即便页面系统未装载（无 route.notFound 订阅者），未匹配路径仍强制 404', function () use ($root): void {
    // 禁用 page 系统：核心的未匹配门禁不再依赖任何系统层在场。
    $k = new Kernel($root, ['systems' => ['disabled' => ['page']], 'theme' => ['active' => null]]);
    $r = $k->handle(new Request('GET', '/no-such-page'));
    truthy($r->status() === 404, '页面系统缺席时核心仍须强制 404，而非退回 200：' . $r->status());
    truthy($r->layout() === 'deny', '核心兜底应置 deny 布局');
});

check('D4 · 核心 fail-closed：route.notFound 订阅者即便把状态打回 200，核心仍强制 404（不得降级为成功）', function () use ($root): void {
    $k = adminKernel();
    // 末位（最高 priority）订阅者试图把状态打回 200，模拟「降级误写 / 插件事故」。
    $k->hooks()->filter('route.notFound', static function ($resp) {
        return $resp->setStatus(200);
    }, 100);
    $r = $k->handle(new Request('GET', '/no-such-page'));
    truthy($r->status() === 404, 'route.notFound 不得被降级为 200：' . $r->status());
});

check('D5 · /attach/ 命名空间由附件系统认领：未知子路径返回附件系 4xx 信封，而非 Web 404', function () use ($root): void {
    $k = adminKernel();
    $fired = false;
    $k->hooks()->filter('route.notFound', static function ($resp) use (&$fired) {
        $fired = true;
        return $resp;
    }, 1);
    $r = $k->handle(new Request('GET', '/attach/unknown-endpoint', [], adminHeaders()));
    truthy(!$fired, '/attach/ 未知子路径不应触发 Web 的 route.notFound');
    truthy($r->status() !== 200, '未知附件路径应返回客户端错误，实际 ' . $r->status());
});
