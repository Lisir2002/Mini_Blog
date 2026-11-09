<?php
declare(strict_types=1);

/**
 * 22a · 性能边界（规矩 T 第一条：不越界）。
 *
 * 用户设想的「动态分配核心 / CPU 调度 / 线程调度」属于部署层。
 * 这里守的是：生产代码里一个这类调用都没有，而部署配置只写在文档里。
 */
echo "\n=== 22a. 性能边界：CPU / 线程 / 进程调度不进应用代码 ===\n";

check('生产代码零 CPU 亲和性 / fork / 常驻运行时调用', function () use ($root): void {
    $banned = [
        'sched_setaffinity', 'taskset', 'pcntl_fork', 'pcntl_setpriority',
        'proc_open', 'popen(', 'Swoole', 'RoadRunner', 'pthreads', 'parallel\\',
    ];
    $dirs = ['core', 'systems', 'plugins', 'pages', 'themes', 'config', 'public'];
    $offenders = [];

    foreach ($dirs as $dir) {
        $base = $root . '/' . $dir;
        if (!is_dir($base)) continue;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') continue;
            $src = (string) file_get_contents($file->getPathname());
            foreach ($banned as $word) {
                if (str_contains($src, $word)) {
                    $offenders[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1)) . ' → ' . $word;
                }
            }
        }
    }
    truthy($offenders === [], '生产代码出现越界调用：' . implode('；', array_slice($offenders, 0, 4)));
});

check('性能能力未新增系统层、未撑大核心', function () use ($root): void {
    $layers = glob($root . '/systems/*', GLOB_ONLYDIR) ?: [];
    truthy(count($layers) === 17, '系统层数应为 17，实际 ' . count($layers));

    $coreFiles = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/core', FilesystemIterator::SKIP_DOTS)
    );
    $n = 0;
    foreach ($coreFiles as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') $n++;
    }
    truthy($n === 24, 'core/ 的 PHP 文件应为 24，实际 ' . $n);
    truthy(!is_file($root . '/plugins/Performance/system.json'), '增强包不得伪装成系统层');
    truthy(is_file($root . '/plugins/Performance/plugin.json'), '增强包必须有包清单');
});

check('部署层参数只写文档、开关默认关闭', function () use ($root): void {
    $doc = (string) file_get_contents($root . '/docs/PERFORMANCE.md');
    foreach (['pm.max_children', 'opcache', 'cpu.max', 'taskset'] as $keyword) {
        truthy(str_contains($doc, $keyword), '部署清单缺少 ' . $keyword);
    }
    $config = require $root . '/config/app.php';
    truthy(($config['performance']['enabled'] ?? true) === false, '性能增强必须默认关闭');
});
