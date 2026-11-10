<?php
declare(strict_types=1);

/**
 * 30 · 启动期边界 —— 「构造」失败时也必须给出可判断的 500。
 *
 * 背景：`run()` 的异常边界只罩住 handle()。配置在 Kernel 构造期就被 require，
 * 损坏的 config/local.php 会让异常发生在边界之外，实际形态是：
 *   display_errors=0 → HTTP/1.0 500 空 body（白屏）
 *   display_errors=1 → HTTP/1.1 200 + 绝对路径与 Parse error（状态码说成功）
 * 后者比白屏更危险：监控看到 200，缓存与爬虫会把错误页当正常页收下。
 *
 * 这里守三件事：入口只有一条路、兜底只有一份正文、构造期故障真的被接住。
 * 真实 SAPI 下的端到端验证由守卫 29 执行（同一条 FaultJourney）。
 */
echo "\n=== 30. 启动期边界：构造失败也要给出可判断的 500 ===\n";

check('入口只有一条路径：public/index.php 必须走 Kernel::serve()', function () use ($root): void {
    $idx = (string) file_get_contents($root . '/public/index.php');
    truthy(str_contains($idx, 'Kernel::serve('), '入口未走统一边界');
    truthy(preg_match('/new\s+\\\\?Wx\\\\Core\\\\Kernel\s*\(/', $idx) === 0,
        '入口自己 new Kernel：构造期异常落在 run() 之外');
    truthy(substr_count($idx, 'http_response_code') === 0, '入口自己写状态码，边界被复制到核心之外');
});

check('兜底只有一份：正文与错误显示设置无关', function () use ($root): void {
    $src = (string) file_get_contents($root . '/core/Kernel.php');
    truthy(str_contains($src, 'public static function serve(string $root): void'),
        'Kernel::serve() 缺失或签名变化');
    truthy(substr_count($src, '500 Internal Server Error') === 1,
        '兜底正文出现多处，容易出现两个版本');
    truthy(str_contains($src, 'error_log('), '构造期故障不写任何日志，排障无从下手');
});

check('构造期故障：截断的 local.php 得到 500 纯文本，不泄露路径', function () use ($root): void {
    // 必须在独立进程里验证：同进程早已有输出，headers_sent() 为真，兜底会被正确地跳过。
    // 这恰好说明「白屏」的成因——边界之前一旦有输出，连兜底都写不出去。
    $tmp = sys_get_temp_dir() . '/wx-boot-' . bin2hex(random_bytes(4));
    mkdir($tmp . '/config', 0777, true);
    file_put_contents($tmp . '/config/app.php', "<?php\nreturn [];\n");
    file_put_contents($tmp . '/config/local.php', "<?php\nreturn ['theme' => [\n");
    file_put_contents($tmp . '/boot.php', "<?php\n"
        . 'require ' . var_export($root . '/core/bootstrap.php', true) . ";\n"
        . "ini_set('error_log', __DIR__ . '/error.log');\n"
        . "ob_start();\n"
        . "http_response_code(200);\n"
        . "\\Wx\\Core\\Kernel::serve(__DIR__);\n"
        . "echo json_encode(['code' => http_response_code(), 'body' => ob_get_clean()]);\n");

    $out = [];
    $rc = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp . '/boot.php') . ' 2>/dev/null', $out, $rc);
    $json = (array) json_decode(implode("\n", $out), true);
    $logged = (string) @file_get_contents($tmp . '/error.log');

    @unlink($tmp . '/boot.php');
    @unlink($tmp . '/error.log');
    @unlink($tmp . '/config/app.php');
    @unlink($tmp . '/config/local.php');
    @rmdir($tmp . '/config');
    @rmdir($tmp);

    truthy($rc === 0, '子进程异常退出：' . implode(' ', array_slice($out, 0, 3)));
    truthy(($json['code'] ?? 0) === 500, '状态码应为 500，实际 ' . var_export($json['code'] ?? null, true));
    truthy(($json['body'] ?? '') === "500 Internal Server Error\n",
        '正文不是标准兜底：' . substr((string) ($json['body'] ?? ''), 0, 120));
    truthy(!str_contains((string) ($json['body'] ?? ''), $tmp), '正文泄露了绝对路径');
    truthy(str_contains($logged, 'wx: unhandled:'), '故障没有进日志，排障将无从下手');
});

check('启动期场景走真实 SAPI：不直调 handle()，服务器必被回收', function () use ($root): void {
    $fj = (string) file_get_contents($root . '/tools/faults/FaultJourney.php');
    truthy(str_contains($fj, 'new StartupFaults()'), 'FaultJourney 未接入启动期故障');

    $cli = (string) file_get_contents($root . '/tools/faults.php');
    truthy(str_contains($cli, 'StartupFaults') && str_contains($cli, "'Server'"),
        'CLI 未加载 Server / StartupFaults');

    $sf = (string) file_get_contents($root . '/tools/faults/StartupFaults.php');
    truthy(str_contains($sf, "Server::start(\$dir . '/public', true)"),
        '未在 display_errors=1 下验证（那正是泄露路径的开关）');
    truthy(str_contains($sf, 'assertBareError'), '缺少统一的兜底形态断言');
    truthy(str_contains($sf, '} finally {') && str_contains($sf, 'stop()'), '服务器未在 finally 回收');

    $srv = (string) file_get_contents($root . '/tools/journey/Server.php');
    truthy(str_contains($srv, 'PHP_BINARY'), '未使用当前 PHP 解释器，环境可能与生产不一致');
    truthy(str_contains($srv, 'proc_terminate'), '服务器进程未强制回收，端口会泄漏');
    truthy(str_contains($srv, 'stream_socket_server'), '未做端口探测，固定端口会互相踩踏');
});
