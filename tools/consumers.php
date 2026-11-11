<?php
declare(strict_types=1);

/**
 * 接口消费方矩阵 —— 谁在用哪个 operation，哪些没有任何消费方。
 *
 * 为什么需要它：能力「已实现」与「用得上」是两件事。前几版反复出现
 * 「接口完备、页面没人调」的断裂（举报队列、关键词搜索、内容生命周期），
 * 都是靠人肉翻 registry.json 才发现的。这个脚本把这件事变成可重跑的查询：
 *
 *   php tools/consumers.php            # 全量矩阵
 *   php tools/consumers.php --zero     # 只看零消费
 *   php tools/consumers.php --json     # 机器可读，供守卫断言
 *
 * 扫描范围刻意只包含「会真正调用接口的地方」：pages/、主题的 assets 脚本、tools/。
 * 系统层自己 registerOperation 不算消费方 —— 那是实现，不是使用。
 */
$root = dirname(__DIR__);
$json = in_array('--json', $args = array_slice($argv, 1), true);
$onlyZero = in_array('--zero', $args, true);

$registry = json_decode((string) file_get_contents($root . '/api/registry.json'), true);
$endpoints = (array) ($registry['endpoints'] ?? []);

/** @param list<string> $ops 已知 operation，用于识别「作为字符串被引用」的动态调用
 *  @param list<string> $linkPaths 非 /api/v1 前缀的接口路径（二进制通道），用于识别链接消费
 *  @return array{exec: array<string, list<string>>, refs: array<string, list<string>>, seam: array<string, list<string>>} */
function consumers(string $root, array $ops, array $linkPaths): array
{
    $map = [];
    $refs = [];
    $seam = [];
    $known = array_flip($ops);
    $dirs = [$root . '/pages', $root . '/tools'];
    foreach (glob($root . '/themes/*/assets', GLOB_ONLYDIR) ?: [] as $assets) $dirs[] = $assets;

    foreach ($dirs as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile()) continue;
            $ext = $file->getExtension();
            if (!in_array($ext, ['php', 'js'], true)) continue;
            $rel = ltrim(str_replace($root, '', $file->getPathname()), '/');
            // 工具自身与消费方矩阵本身不算「使用接口」。
            if (str_contains($rel, 'tools/consumers.php')) continue;
            $src = (string) file_get_contents($file->getPathname());

            if ($ext === 'php') {
                preg_match_all('/execute\(\s*[\'"]([a-z0-9_.]+)[\'"]/i', $src, $m);
                foreach ($m[1] as $op) $map[$op][] = $rel;
                // 动态拼接的调用（如 $intent === 'reply' ? 'x' : 'y'）扫不到 execute()，
                // 但操作名一定以字符串字面量出现；标成「引用」，与确证消费区分开。
                //
                // 必须先剔除 ACL 裁决调用：有些系统的权限点与 operation 同名
                // （member 系统六个权限点全部如此）。`allows($identity, 'member.xp.award')`
                // 只是「问能不能发经验」，不是「已经有人发经验」。不剔除的话，
                // 一个只读页里的权限裁剪就会把写接口伪装成已消费 —— 零消费清单
                // 从此永远看不见它，直到有人去读代码。字符串字面量只能证明「提到过」，
                // 这里是唯一能明确判否的上下文，所以按调用整段抹掉再扫。
                $refSrc = (string) preg_replace('/(?:->|::)\s*allows\s*\([^)]*\)/i', '', $src);
                preg_match_all('/[\'"]([a-z0-9_.]+)[\'"]/i', $refSrc, $r);
                foreach ($r[1] as $op) if (isset($known[$op])) $refs[$op][] = $rel;

                // 有些接口**不该**被页面 execute()：二进制通道一旦直调，文件字节
                // 就进了页面层。它们只通过 <a href> / url 字段被消费。矩阵若只认
                // 「服务端调用」，会把这类接口永远列成零消费，等于逼着下一个人
                // 去写一个错误的调用方。因此把「页面里出现了该路径字面量」也算消费，
                // 与 JS 的 path: 落在同一个 @path 桶里。
                foreach ($linkPaths as $path) {
                    if (str_contains($src, "'" . $path) || str_contains($src, '"' . $path)) {
                        $map['@path:' . $path][] = $rel;
                    }
                }
            } else {
                preg_match_all('/path:\s*[\'"]([^\'"]+)[\'"]/', $src, $m);
                foreach ($m[1] as $path) $map['@path:' . $path][] = $rel;
            }
        }
    }
    foreach ([&$map, &$refs] as &$bucket) {
        foreach ($bucket as $op => $files) {
            $bucket[$op] = array_values(array_unique($files));
            sort($bucket[$op]);
        }
        ksort($bucket);
    }

    // 系统层的「接缝」：页面不直接 execute，而是经过一个有名字的服务（如 PageSession
    // 调 auth.session.create）。这类调用不该被当成实现细节抹掉，也不该把整个 systems/
    // 都算成消费方；只认显式 `@consumes op` 声明，且声明必须能在清单里找到。
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $root . '/systems', FilesystemIterator::SKIP_DOTS
    ));
    foreach ($it as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') continue;
        $src = (string) file_get_contents($file->getPathname());
        if (!str_contains($src, '@consumes')) continue;
        $rel = ltrim(str_replace($root, '', $file->getPathname()), '/');
        preg_match_all('/@consumes\s+([a-z0-9_.]+)/i', $src, $m);
        foreach ($m[1] as $op) if (isset($known[$op])) $seam[$op][] = $rel;
    }
    foreach ($seam as $op => $files) {
        $seam[$op] = array_values(array_unique($files));
        sort($seam[$op]);
    }
    ksort($seam);
    return ['exec' => $map, 'refs' => $refs, 'seam' => $seam];
}

$ops = array_values(array_filter(array_map(
    static fn(array $e): string => (string) ($e['operation'] ?? ''), $endpoints
)));
// 只有「不在 /api/v1 下」的接口才可能是二进制通道；把它们交给链接识别。
$linkPaths = array_values(array_unique(array_filter(array_map(
    static fn(array $e): string => (string) ($e['path'] ?? ''), $endpoints
), static fn(string $p): bool => $p !== '' && !str_starts_with($p, '/api/v1/'))));
$scan = consumers($root, $ops, $linkPaths);
$used = $scan['exec'];
$rows = [];
$zero = [];
foreach ($endpoints as $endpoint) {
    $op = (string) ($endpoint['operation'] ?? '');
    if ($op === '') continue;
    $byPath = $used['@path:' . (string) ($endpoint['path'] ?? '')] ?? [];
    $files = array_values(array_unique(array_merge($used[$op] ?? [], $byPath)));
    sort($files);
    $kind = 'exec';
    if ($files === []) {
        $files = $scan['seam'][$op] ?? [];
        $kind = $files === [] ? 'none' : 'seam';
    }
    if ($files === []) {
        $files = $scan['refs'][$op] ?? [];
        $kind = $files === [] ? 'none' : 'ref';
    }
    $rows[$op] = ['id' => (string) ($endpoint['id'] ?? ''), 'method' => (string) ($endpoint['method'] ?? ''),
        'path' => (string) ($endpoint['path'] ?? ''), 'consumers' => $files, 'kind' => $kind];
    if ($kind === 'none') $zero[$op] = $rows[$op];
}

if ($json) {
    echo json_encode([
        'total' => count($rows), 'consumed' => count($rows) - count($zero), 'zero' => $zero,
        'all' => $rows,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    exit(0);
}

echo "=== 接口消费方矩阵 ===\n";
echo '共 ' . count($rows) . ' 个 operation；有消费方 ' . (count($rows) - count($zero))
    . '，零消费 ' . count($zero) . "\n\n";

if (!$onlyZero) {
    echo "--- 已消费 ---\n";
    foreach ($rows as $op => $row) {
        if ($row['consumers'] === []) continue;
        $mark = $row['kind'] === 'ref' ? '~ ' : ($row['kind'] === 'seam' ? '≈ ' : '');
        echo '  ' . str_pad($op, 30) . '<- ' . $mark . implode(', ', $row['consumers']) . "\n";
    }
    echo "\n";
}

echo "--- 零消费（能力存在，但没有任何页面 / 脚本调用）---\n";
if ($zero === []) {
    echo "  无\n";
} else {
    foreach ($zero as $op => $row) {
        echo '  ' . str_pad($op, 30) . $row['id'] . '  ' . $row['method'] . ' ' . $row['path'] . "\n";
    }
}
