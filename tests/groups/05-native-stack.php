<?php
declare(strict_types=1);

echo "\n=== 5. 原生栈守卫（无 Twig / Blade / 第三方框架） ===\n";

check('全项目无 composer / vendor / 模板引擎依赖', function () use ($root): void {
    truthy(!is_file($root . '/composer.json'), '出现 composer.json');
    truthy(!is_dir($root . '/vendor'), '出现 vendor/');
    $bannedExt = ['twig', 'tpl', 'latte'];
    $bannedUsage = ['vendor/autoload', 'use twig\\', 'new twig\\', 'twig_environment',
        'illuminate\\', 'symfony\\', 'smarty(', 'blade::'];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $path = str_replace('\\', '/', $file->getPathname());
        if (str_contains($path, '/.codebuddy/') || str_contains($path, '/storage/') || str_contains($path, '/tests/')) continue;
        foreach ($bannedExt as $ext) {
            truthy($file->getExtension() !== $ext, "出现模板引擎文件：{$path}");
        }
        truthy(!str_ends_with($file->getFilename(), '.blade.php'), "出现 Blade 模板：{$path}");
        if ($file->getExtension() !== 'php') continue;
        $source = strtolower((string) file_get_contents($path));
        foreach ($bannedUsage as $needle) {
            truthy(!str_contains($source, $needle), "源码出现第三方依赖用法 {$needle}：{$path}");
        }
    }
});

check('主题使用原生 PHP 模板，渲染器不拼接 HTML', function () use ($root): void {
    foreach (['themes/Bare/templates/page.php', 'themes/BareHtml/templates/page.php'] as $rel) {
        $tpl = $root . '/' . $rel;
        truthy(is_file($tpl), '缺少原生 PHP 模板文件：' . $rel);
        $text = (string) file_get_contents($tpl);
        truthy(str_contains($text, '<?='), '模板未使用原生 PHP 短输出语法：' . $rel);
        truthy(str_contains($text, 'endforeach'), '模板未使用原生 PHP 控制结构：' . $rel);
    }
    foreach (['themes/Bare/BareRenderer.php', 'themes/BareHtml/BareHtmlRenderer.php'] as $rel) {
        $renderer = (string) file_get_contents($root . '/' . $rel);
        truthy(str_contains($renderer, 'include $this->dir'), '渲染器未 include 原生模板：' . $rel);
        truthy(!str_contains($renderer, '<html'), '渲染器仍在拼接 HTML 字符串：' . $rel);
    }
});

check('规矩 J：CSS/JS 只存在于主题包，core/ systems/ pages/ plugins/ 一律零样式', function () use ($root): void {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    $found = [];
    foreach ($it as $file) {
        if (!$file->isFile() || !in_array($file->getExtension(), ['css', 'js'], true)) continue;
        $rel = ltrim(str_replace('\\', '/', str_replace($root, '', $file->getPathname())), '/');
        if (str_starts_with($rel, '.codebuddy/') || str_starts_with($rel, 'storage/')) continue;
        truthy(str_starts_with($rel, 'themes/'), '样式/脚本越权出现在主题包之外：' . $rel);
        $found[] = $rel;
    }
    truthy($found !== [], '未找到任何主题样式，守卫本身可能失效');
});
