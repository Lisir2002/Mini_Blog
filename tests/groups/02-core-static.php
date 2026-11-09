<?php
declare(strict_types=1);

echo "\n=== 2. 核心目录静态守卫 ===\n";

check('core/ 下不存在任何 .css / .js 文件', function () use ($root): void {
    $files = array_merge(
        glob($root . '/core/*.css') ?: [], glob($root . '/core/*.js') ?: [],
        glob($root . '/core/*/*.css') ?: [], glob($root . '/core/*/*.js') ?: []
    );
    truthy($files === [], '核心目录出现样式/脚本文件：' . implode(', ', $files));
});

check('core/ 源码中不存在任何 HTML 表现层痕迹', function () use ($root): void {
    $markers = ['<html', '<style', '<script', '<link', '<body', '<div', '<!doctype', 'echo \'<'];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/core'));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') continue;
        $source = strtolower((string) file_get_contents($file->getPathname()));
        foreach ($markers as $marker) {
            truthy(!str_contains($source, $marker),
                $file->getFilename() . ' 中出现硬编码表现层：' . $marker);
        }
    }
});

check('核心 PHP 文件数 <= 24，保持轻量（新增契约只是接口面扩张）', function () use ($root): void {
    $count = count(glob($root . '/core/*.php') ?: [])
        + count(glob($root . '/core/*/*.php') ?: []);
    truthy($count <= 24, "核心文件数为 {$count}，超出轻量预算");
});
