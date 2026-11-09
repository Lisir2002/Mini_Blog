<?php
declare(strict_types=1);

/**
 * 源包签名工具 —— 纯原生 PHP，无第三方依赖。
 * 生成 manifest.sha256（完整性清单）与 signature.json（HMAC-SHA256 签名）。
 *
 * 用法：
 *   php tools/sign-package.php <源包目录> <签名密钥>
 *   php tools/sign-package.php <源包目录>          # 只生成完整性清单，不签名
 */
require dirname(__DIR__) . '/core/bootstrap.php';

use Wx\Systems\Lifecycle\Fs;

$dir    = (string) ($argv[1] ?? '');
$secret = (string) ($argv[2] ?? '');
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "用法：php tools/sign-package.php <源包目录> [签名密钥]\n");
    exit(1);
}
$real = (string) realpath($dir);

file_put_contents(
    $real . '/manifest.sha256',
    (string) json_encode(Fs::hashDir($real), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
);

if ($secret !== '') {
    file_put_contents(
        $real . '/signature.json',
        (string) json_encode([
            'algorithm' => 'HMAC-SHA256',
            'signature' => hash_hmac('sha256', Fs::canonical($real), $secret),
            'signed_at' => date(DATE_ATOM),
            '_note'     => '由 tools/sign-package.php 生成；验签密钥来自 config.lifecycle.signature_secret',
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    );
}
echo "已处理：{$real}（" . count(Fs::hashDir($real)) . " 个文件）\n";
