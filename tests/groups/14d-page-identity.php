<?php
declare(strict_types=1);

use Wx\Core\Http\Request;

echo "\n=== 14d. 页面身份载体同源与不可区分性 ===\n";

check('无效令牌与无令牌同为 401，不探知令牌有效性差异', function (): void {
    $r = adminKernel()->handle(new Request('GET', '/console', [], ['Authorization' => 'Bearer not-a-token']));
    truthy($r->status() === 401
        && (json_decode($r->body(), true)['reason'] ?? null) === 'unauthenticated',
        '无效令牌应统一 401 / unauthenticated');
});

check('三种身份载体同源：Bearer / X-Api-Token / Cookie 都走同一个认证器', function (): void {
    $k = adminKernel();
    foreach ([
        ['Authorization' => 'Bearer test-admin-token'],
        ['X-Api-Token'   => 'test-admin-token'],
        ['Cookie'        => 'wx_token=test-admin-token; theme=dark'],
    ] as $headers) {
        $r = $k->handle(new Request('GET', '/console', [], $headers));
        truthy($r->status() === 200, '身份载体未被识别：' . json_encode($headers));
        truthy((json_decode($r->body(), true)['identity']['username'] ?? null) === 'admin',
            '身份未贯通到页面数据');
    }
});

check('Cookie 里的无效令牌同样 401，不因载体不同而改变裁决', function (): void {
    $r = adminKernel()->handle(new Request('GET', '/console', [], ['Cookie' => 'wx_token=bad-token']));
    truthy($r->status() === 401, '无效 Cookie 令牌未返回 401');
    truthy((json_decode($r->body(), true)['reason'] ?? null) === 'unauthenticated', '拒绝原因不对');
});
