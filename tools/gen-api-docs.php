<?php
declare(strict_types=1);

/**
 * 接口操作文档生成器。
 *
 * 「一个接口对应一份规范化操作文档」的落地方式：
 *   api/registry.json（唯一事实源） + docs/api/_TEMPLATE.md → docs/api/<ID>.md
 * 由 tests/run.php 守卫：清单与文档 1:1、章节齐全、无孤儿文档。
 *
 * 用法：php tools/gen-api-docs.php
 */
require dirname(__DIR__) . '/core/bootstrap.php';

use Wx\Systems\Api\Envelope;

$root = dirname(__DIR__);
$registryFile = $root . '/api/registry.json';
$templateFile = $root . '/docs/api/_TEMPLATE.md';
$docDir = $root . '/docs/api';

$data = json_decode((string) file_get_contents($registryFile), true);
if (!is_array($data) || !isset($data['endpoints'])) {
    fwrite(STDERR, "接口清单解析失败\n");
    exit(1);
}
$template = (string) file_get_contents($templateFile);
$version = (string) ($data['version'] ?? 'v1');

$generated = [];
foreach ($data['endpoints'] as $endpoint) {
    $id = (string) $endpoint['id'];
    $params = (array) ($endpoint['params'] ?? []);
    $fields = (array) ($endpoint['response_fields'] ?? []);

    if ($params === []) {
        $paramsTable = '**无请求参数。**';
    } else {
        $paramsTable = "| 参数 | 位置 | 必填 | 类型 | 说明 |\n| --- | --- | --- | --- | --- |\n";
        foreach ($params as $p) {
            $paramsTable .= sprintf(
                "| `%s` | %s | %s | `%s` | %s |\n",
                $p['name'], $p['in'], ($p['required'] ?? false) ? '是' : '否', $p['type'], $p['desc']
            );
        }
    }

    $responseTable = "| 字段 | 类型 | 说明 |\n| --- | --- | --- |\n";
    foreach ($fields as $f) {
        $responseTable .= sprintf("| `%s` | `%s` | %s |\n", $f['name'], $f['type'], $f['desc']);
    }

    $errorsTable = '';
    foreach ((array) ($endpoint['errors'] ?? []) as $code) {
        $entry = Envelope::TABLE[(int) $code] ?? [500, '未登记的错误码'];
        $errorsTable .= sprintf("| `%d` | %d | %s |\n", (int) $code, $entry[0], $entry[1]);
    }

    $idempotent = (bool) ($endpoint['idempotent'] ?? false);
    $effects = (string) ($endpoint['side_effects'] ?? 'none');
    $retry = $idempotent
        ? '可安全重试，重复调用结果一致。'
        : '不可盲目重试；请先查询单元状态，或携带幂等键后由服务端去重。';

    $markdown = strtr($template, [
        '{{ID}}'              => $id,
        '{{NAME}}'            => (string) $endpoint['name'],
        '{{SYSTEM}}'          => (string) $endpoint['system'],
        '{{METHOD}}'          => (string) $endpoint['method'],
        '{{PATH}}'            => (string) $endpoint['path'],
        '{{OPERATION}}'       => (string) $endpoint['operation'],
        '{{AUTH}}'            => (string) $endpoint['auth'],
        '{{PERMISSION}}'      => (string) ($endpoint['permission'] ?? '无（仅按鉴权级别裁决）'),
        '{{VERSION}}'         => $version,
        '{{SINCE}}'           => (string) $endpoint['since'],
        '{{DOC}}'             => (string) $endpoint['doc'],
        '{{SUMMARY}}'         => (string) $endpoint['summary'],
        '{{SCENARIO}}'        => (string) $endpoint['scenario'],
        '{{NOTES}}'           => (string) ($endpoint['notes'] ?? '无。'),
        '{{PARAMS_TABLE}}'    => rtrim($paramsTable),
        '{{RESPONSE_TABLE}}'  => rtrim($responseTable),
        '{{ERRORS_TABLE}}'    => rtrim($errorsTable),
        '{{EXAMPLE_REQUEST}}' => (string) $endpoint['example_request'],
        '{{EXAMPLE_RESPONSE}}'=> json_encode($endpoint['example_response'] ?? new stdClass(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        '{{IDEMPOTENT}}'      => $idempotent ? '是' : '否',
        '{{SIDE_EFFECTS}}'    => match ($effects) {
            'filesystem' => '写文件系统（安装 / 升级 / 卸载 / 回滚）',
            'state'      => '仅写装卸状态',
            default      => '无',
        },
        '{{RETRY}}'           => $retry,
        '{{CHANGELOG}}'       => '- ' . implode("\n- ", (array) ($endpoint['changelog'] ?? [])),
    ]);

    $target = $root . '/' . $endpoint['doc'];
    if (!is_dir(dirname($target))) mkdir(dirname($target), 0775, true);
    file_put_contents($target, $markdown);
    $generated[] = $id;
}

// 索引：同样由清单生成，避免人工维护导致漂移
$index = "# 接口操作文档索引\n\n"
       . "> 由 `php tools/gen-api-docs.php` 生成。接口清单：`api/registry.json`；设计规范：`docs/api/SPEC.md`。\n"
       . "> 规矩：**一个接口对应一份文档**，编号即文件名，缺一份或未更新都会被 `tests/run.php` 拦下。\n\n"
       . "| 编号 | 方法 | 路径 | 所属系统层 | 鉴权 | 幂等 | 文档 |\n"
       . "| --- | --- | --- | --- | --- | --- | --- |\n";
foreach ($data['endpoints'] as $endpoint) {
    $index .= sprintf(
        "| `%s` | `%s` | `%s` | `%s` | `%s` | %s | [%s](%s) |\n",
        $endpoint['id'], $endpoint['method'], $endpoint['path'], $endpoint['system'],
        $endpoint['auth'], ($endpoint['idempotent'] ?? false) ? '是' : '否',
        $endpoint['id'], basename((string) $endpoint['doc'])
    );
}
$index .= "\n共 " . count($generated) . " 个接口，接口版本 `{$version}`。\n";
file_put_contents($docDir . '/README.md', $index);

echo '已生成 ' . count($generated) . " 份接口文档 + 索引\n";
