<?php
declare(strict_types=1);

/**
 * 规矩 O 的守卫：项目树纪律。
 *
 * 调试现场产生的验证脚本、临时文件、数据库转储、日志，一律不进项目目录；
 * 项目里只允许两种「可重跑的证据」：tests/ 下的守卫与 tools/ 下的正式工具。
 * 守卫由 tests/run.php 自动加载，所以它自己也在被这条规矩约束。
 */

/**
 * 项目根目录的相对路径；统一分隔符，便于规则匹配。
 */
function hygieneRel(string $root, string $path): string
{
    return str_replace('\\', '/', substr($path, strlen($root) + 1));
}

check('项目树纪律：根目录只允许已登记的条目', function () use ($root): void {
    $allowed = [
        'README.md', '.gitignore',
        'api', 'config', 'core', 'docs', 'pages',
        'plugins', 'public', 'storage', 'systems', 'tests', 'themes', 'tools',
    ];
    $unexpected = [];
    foreach (scandir($root) as $entry) {
        if (in_array($entry, ['.', '..', '.codebuddy'], true)) {
            continue;   // .codebuddy 是编辑器项目数据，不属于交付内容
        }
        if (!in_array($entry, $allowed, true)) {
            $unexpected[] = $entry;
        }
    }
    truthy($unexpected === [], '根目录出现未登记条目：' . implode('、', $unexpected));
});

check('项目树纪律：无验证脚本 / 临时文件 / 日志 / 数据库转储', function () use ($root): void {
    $banned = ['py', 'sh', 'bash', 'ps1', 'bat', 'cmd', 'bak', 'orig', 'rej',
               'tmp', 'swp', 'swo', 'log', 'sqlite', 'sqlite3', 'db', 'dump', 'sql'];
    $offenders = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($files as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $rel = hygieneRel($root, $file->getPathname());
        if (str_starts_with($rel, '.codebuddy/')) {
            continue;
        }
        if (str_ends_with($file->getFilename(), '~')) {
            $offenders[] = $rel;
            continue;
        }

        $ext = strtolower($file->getExtension());
        if (!in_array($ext, $banned, true)) {
            continue;
        }
        // 两处设计内例外：运行日志与各系统层的建表迁移。
        if ($ext === 'log' && str_starts_with($rel, 'storage/logs/')) {
            continue;
        }
        if ($ext === 'sql' && preg_match('#^systems/[A-Za-z]+/migrations/[^/]+\.sql$#', $rel) === 1) {
            continue;
        }
        $offenders[] = $rel;
    }

    truthy($offenders === [], '项目内出现不该入库的文件：' . implode('、', array_slice($offenders, 0, 6)));
});

check('项目树纪律：不留临时命名的文件', function () use ($root): void {
    $reserved = '/^(tmp|temp|verify|smoke|scratch|playground|dump|bak|debug[-_])/i';
    $offenders = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($files as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $rel = hygieneRel($root, $file->getPathname());
        if (str_starts_with($rel, '.codebuddy/') || $rel === '.gitignore') {
            continue;
        }
        if (preg_match($reserved, $file->getFilename()) === 1) {
            $offenders[] = $rel;
        }
    }
    truthy($offenders === [], '出现一次性命名的文件：' . implode('、', array_slice($offenders, 0, 6)));
});

check('项目树纪律：tools/ 只放可重跑的正式工具', function () use ($root): void {
    $allowed = ['audit', 'audit-responsibility.php', 'export-openapi.php', 'gen-api-docs.php', 'sign-package.php'];
    $unexpected = [];
    foreach (scandir($root . '/tools') as $entry) {
        if (in_array($entry, ['.', '..'], true)) {
            continue;
        }
        if (!in_array($entry, $allowed, true)) {
            $unexpected[] = $entry;
        }
    }
    truthy($unexpected === [], 'tools/ 出现未登记文件：' . implode('、', $unexpected));
});

check('项目树纪律：守卫文件统一编号命名，不夹带脚本', function () use ($root): void {
    foreach (glob($root . '/tests/groups/*') as $path) {
        $name = basename($path);
        truthy(
            preg_match('/^\d{2}[a-z]?-[a-z0-9-]+\.php$/', $name) === 1,
            '守卫文件名不合规（应为 NN[x]-kebab-case.php）：' . $name
        );
    }
});
