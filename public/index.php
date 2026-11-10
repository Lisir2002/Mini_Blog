<?php
declare(strict_types=1);

/** 唯一入口：引导内核 → 运行。这里不放任何业务、任何样式。 */
$wxRoot = require dirname(__DIR__) . '/core/bootstrap.php';

// PHP 内置开发服务器：public/ 下真实存在的静态文件交还服务器处理
if (PHP_SAPI === 'cli-server') {
    $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    $file = __DIR__ . $path;
    if ($path !== '/' && is_file($file)) {
        return false;
    }
}

// 构造期故障（如 config/local.php 损坏）同样要落在边界内：入口不自己写 500。
\Wx\Core\Kernel::serve($wxRoot);
