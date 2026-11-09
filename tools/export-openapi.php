<?php
declare(strict_types=1);

/**
 * 导出标准 OpenAPI 3.1 文档 —— 由 api/registry.json（唯一事实源）生成。
 * 借鉴的是规范，不是依赖：没有引入 YAML 解析器、Schema 库或 API 框架，纯原生 PHP。
 *
 * 用法：php tools/export-openapi.php
 */
require dirname(__DIR__) . '/core/bootstrap.php';

use Wx\Systems\Api\Envelope;

$root     = dirname(__DIR__);
$manifest = $root . '/api/registry.json';
$target   = $root . '/docs/api/openapi.json';

$data = json_decode((string) file_get_contents($manifest), true);
if (!is_array($data) || !isset($data['endpoints'])) {
    fwrite(STDERR, "接口清单解析失败\n");
    exit(1);
}
$version = (string) ($data['version'] ?? 'v1');

$envelopeSchema = [
    'type' => 'object',
    'required' => ['code', 'message', 'data', 'request_id'],
    'properties' => [
        'code'       => ['type' => 'integer', 'description' => '业务码，0 为成功'],
        'message'    => ['type' => 'string'],
        'data'       => ['description' => '成功时的业务数据；失败时恒为 null'],
        'request_id' => ['type' => 'string'],
        'errors'     => ['type' => 'array', 'items' => ['type' => 'object']],
        'detail'     => ['type' => 'string'],
        'meta'       => ['type' => 'object'],
    ],
];

$doc = [
    'openapi' => '3.1.0',
    'info'    => [
        'title'       => 'Mini Blog API',
        'version'     => $version,
        'description' => '由 api/registry.json 生成。统一信封、稳定 operationId、operation 级安全声明、机器可读错误码。',
    ],
    'servers' => [['url' => '/', 'description' => '当前站点']],
    'paths'   => [],
    'components' => [
        'securitySchemes' => [
            'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => '由 POST /api/v1/auth/token 签发'],
        ],
        'schemas' => [
            'Envelope' => $envelopeSchema,
            'Error'    => $envelopeSchema,
        ],
    ],
];

$operations = 0;
foreach ($data['endpoints'] as $endpoint) {
    $method = strtolower((string) $endpoint['method']);
    $path   = (string) $endpoint['path'];

    $parameters = [];
    foreach ((array) ($endpoint['params'] ?? []) as $param) {
        $in = (string) ($param['in'] ?? 'query');
        if ($in === 'body') continue;
        $schema = ['type' => match ((string) ($param['type'] ?? 'string')) {
            'int' => 'integer', 'bool' => 'boolean', 'array' => 'array', default => 'string',
        }];
        $parameters[] = [
            'name'        => (string) $param['name'],
            'in'          => $in,
            'required'    => (bool) ($param['required'] ?? false),
            'description' => (string) ($param['desc'] ?? ''),
            'schema'      => $schema,
        ];
    }

    $bodyParams = array_values(array_filter(
        (array) ($endpoint['params'] ?? []),
        static fn(array $p): bool => ($p['in'] ?? 'query') === 'body'
    ));

    $operation = [
        'operationId' => (string) $endpoint['operation'],
        'summary'     => (string) $endpoint['name'],
        'description' => (string) $endpoint['summary'] . "\n\n适用场景：" . (string) $endpoint['scenario'],
        'tags'        => [(string) $endpoint['system']],
        'parameters'  => $parameters,
        'responses'   => [
            '200' => [
                'description' => '成功',
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Envelope']]],
            ],
        ],
    ];
    if ($bodyParams !== []) {
        $props = [];
        foreach ($bodyParams as $p) {
            $props[(string) $p['name']] = [
                'type' => match ((string) ($p['type'] ?? 'string')) {
                    'int' => 'integer', 'bool' => 'boolean', 'array' => 'array', default => 'string',
                },
                'description' => (string) ($p['desc'] ?? ''),
            ];
        }
        $operation['requestBody'] = [
            'required' => true,
            'content'  => ['application/json' => ['schema' => [
                'type' => 'object', 'required' => array_values(array_map(
                    static fn(array $p): string => (string) $p['name'],
                    array_filter($bodyParams, static fn(array $p): bool => (bool) ($p['required'] ?? false))
                )), 'properties' => $props,
            ]]],
        ];
    }
    if (($endpoint['auth'] ?? 'public') !== 'public') {
        $operation['security'] = [['bearerAuth' => []]];
    }
    if (($endpoint['idempotent'] ?? false) === false) {
        $operation['description'] .= "\n\n非幂等写操作：建议携带 Idempotency-Key。";
    }
    foreach ((array) ($endpoint['errors'] ?? []) as $code) {
        $status = (string) (Envelope::TABLE[(int) $code][0] ?? 500);
        $operation['responses'][$status] = [
            'description' => Envelope::TABLE[(int) $code][1] ?? '错误',
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
        ];
    }
    $doc['paths'][$path][$method] = $operation;
    $operations++;
}

file_put_contents($target, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "已导出 OpenAPI 3.1：{$operations} 个 operation / " . count($doc['paths']) . " 条路径 → docs/api/openapi.json\n";
