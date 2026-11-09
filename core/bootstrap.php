<?php
declare(strict_types=1);

/**
 * 系统核心 · 引导文件
 *
 * 本文件只做一件事：注册自动装载规则。
 * 不含业务逻辑、不含 SQL、不含任何 HTML / CSS / JavaScript。
 */
$wxRoot = dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($wxRoot): void {
    static $map = null;
    $map ??= [
        'Wx\\Contracts\\' => $wxRoot . '/core/Contracts/',
        'Wx\\Core\\'      => $wxRoot . '/core/',
        'Wx\\Systems\\'   => $wxRoot . '/systems/',
        'Wx\\Themes\\'    => $wxRoot . '/themes/',
        'Wx\\Plugins\\'   => $wxRoot . '/plugins/',
        'Wx\\Pages\\'     => $wxRoot . '/pages/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

return $wxRoot;
