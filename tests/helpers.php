<?php
declare(strict_types=1);

/**
 * 守卫测试共享辅助 —— 只提供断言、内核工厂与测试夹具，不含任何断言用例。
 * check() 登记用例名，供第 12 组验证「协作矩阵每一类都真有守卫跑过」。
 */
require dirname(__DIR__) . '/core/bootstrap.php';

use Wx\Core\Kernel;
use Wx\Systems\Lifecycle\Fs;

$root = dirname(__DIR__);
$passed = 0;
$failed = 0;

function check(string $name, callable $fn): void
{
    global $passed, $failed;
    $GLOBALS['wx_checks'][] = $name;
    try {
        $fn();
        echo "  PASS  {$name}\n";
        $passed++;
    } catch (Throwable $e) {
        echo "  FAIL  {$name}\n         " . get_class($e) . ': ' . $e->getMessage() . "\n";
        $failed++;
    }
}

function truthy(mixed $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function kernel(?string $theme): Kernel
{
    global $root;
    return new Kernel($root, ['theme' => ['active' => $theme]]);
}

/** 带静态 admin 令牌的测试内核（静态令牌只用于本地验证，不写入默认配置）。 */
function adminKernel(array $extra = []): Kernel
{
    global $root;
    return new Kernel($root, array_replace_recursive([
        'theme' => ['active' => null],
        'api'   => ['tokens' => [
            'test-admin-token' => ['role' => 'admin', 'username' => 'admin', 'user_id' => 1],
        ]],
    ], $extra));
}

function adminHeaders(): array
{
    return ['Authorization' => 'Bearer test-admin-token'];
}

/** 从 Set-Cookie / Cookie 头里取指定 cookie 的值（解析职责放在辅助里，测试文件不承担）。 */
function cookieValue(string $header, string $name): string
{
    if (preg_match('/(?:^|;\s*)' . preg_quote($name, '/') . '=([^;\s]+)/', $header, $m)) {
        return rawurldecode((string) $m[1]);
    }
    return '';
}

/** 用任意令牌构造 Bearer 头：让测试文件不必出现身份载体字面量（职责审计的职权判定更干净）。 */
function bearer(string $token): array
{
    return ['Authorization' => 'Bearer ' . $token];
}

/**
 * 契约替换机制测试内核：显式关闭装配期授权门槛。
 * 生产默认是 require_grant=true（见 15e 守卫）；这里只验证「契约面可替换」这一机制本身，
 * 避免把两个关注点混在一条断言里。
 */
function contractKernel(?string $theme = null): Kernel
{
    return adminKernel([
        'theme'      => ['active' => $theme],
        'permission' => ['contract_override' => ['require_grant' => false]],
    ]);
}

/** 带静态 admin 令牌、可选主题的测试内核：用于访问受保护页面（v0.8 起后台需身份） */
function pageKernel(?string $theme = null): Kernel
{
    return adminKernel(['theme' => ['active' => $theme]]);
}

/** v0.8：按角色造静态令牌内核，用于页面访问分级守卫 */
function roleKernel(string $role, ?string $theme = null, array $extra = []): Kernel
{
    global $root;
    return new Kernel($root, array_replace_recursive([
        'theme' => ['active' => $theme],
        'api'   => ['tokens' => ['t-' . $role . '-token' => [
            'role' => $role, 'username' => $role, 'user_id' => 9,
        ]]],
    ], $extra));
}

/** @return array<string, string> */
function roleHeaders(string $role): array
{
    return ['Authorization' => 'Bearer t-' . $role . '-token'];
}

/** 清空某类接口运行时存储，保证限流 / 幂等断言从确定状态开始。 */
function resetStorage(string $sub): void
{
    global $root;
    $dir = $root . '/storage/api/' . $sub;
    if (is_dir($dir)) Fs::removeDir($dir);
    mkdir($dir, 0775, true);
}

/** 造一个带 SHA-256 清单的源包，可选自带原生 PHP 生命周期钩子。 */
function makeSourcePackage(string $dir, string $version, array $requires = [], bool $withHook = false, string $slug = 'demo'): void
{
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $meta = ['slug' => $slug, 'version' => $version, 'enabled' => true];
    if ($requires !== []) $meta['requires'] = $requires;
    file_put_contents($dir . '/plugin.json', json_encode($meta, JSON_UNESCAPED_UNICODE));
    file_put_contents($dir . '/Demo.php', "<?php\n// demo package\n");
    if ($withHook) {
        $hook = <<<'PHP'
<?php
return [
    'on_install' => static function (array $ctx): void {
        file_put_contents(__DIR__ . '/installed.marker', $ctx['version']);
    },
];
PHP;
        file_put_contents($dir . '/lifecycle.php', $hook);
    }
    file_put_contents($dir . '/manifest.sha256', json_encode(Fs::hashDir($dir), JSON_UNESCAPED_SLASHES));
}

/** 用 HMAC-SHA256 为源包生成签名文件（与 tools/sign-package.php 同算法）。 */
function signPackage(string $dir, string $secret): void
{
    file_put_contents($dir . '/manifest.sha256', json_encode(Fs::hashDir($dir), JSON_UNESCAPED_SLASHES));
    file_put_contents($dir . '/signature.json', json_encode([
        'algorithm' => 'HMAC-SHA256',
        'signature' => hash_hmac('sha256', Fs::canonical($dir), $secret),
        'signed_at' => date(DATE_ATOM),
    ]));
}
