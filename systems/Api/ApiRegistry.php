<?php
declare(strict_types=1);
namespace Wx\Systems\Api;

use RuntimeException;
use Wx\Contracts\ApiRegistryInterface;

/**
 * 接口清单注册表 —— 唯一事实源。
 *
 * 结构化约束在此强制：必需字段、ID 唯一、(method,path) 唯一、operation 唯一、文档路径存在。
 * 文档内容的一致性由 tests/run.php 守卫，避免清单与文档漂移。
 */
final class ApiRegistry implements ApiRegistryInterface
{
    private const REQUIRED = [
        'id', 'name', 'system', 'method', 'path', 'operation', 'auth',
        'idempotent', 'since', 'summary', 'scenario', 'params',
        'response_fields', 'errors', 'changelog', 'doc',
    ];

    private array $endpoints = [];
    private array $byId = [];
    private array $byOperation = [];
    private array $operations = [];
    private array $paths = [];

    public function __construct(private string $file)
    {
        if (!is_file($file)) {
            throw new RuntimeException('接口清单不存在：' . $file);
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || !isset($data['endpoints']) || !is_array($data['endpoints'])) {
            throw new RuntimeException('接口清单格式错误：' . $file);
        }
        foreach ($data['endpoints'] as $index => $endpoint) {
            if (!is_array($endpoint)) {
                throw new RuntimeException("第 {$index} 条接口定义不是对象");
            }
            foreach (self::REQUIRED as $field) {
                if (!array_key_exists($field, $endpoint)) {
                    throw new RuntimeException("接口定义缺少字段 {$field}：第 {$index} 条");
                }
            }
            $id = (string) $endpoint['id'];
            $route = $endpoint['method'] . ' ' . $endpoint['path'];
            $operation = (string) $endpoint['operation'];
            if (isset($this->byId[$id])) throw new RuntimeException('接口 ID 重复：' . $id);
            if (isset($this->paths[$route])) throw new RuntimeException('接口路由重复：' . $route);
            if (isset($this->byOperation[$operation])) throw new RuntimeException('operation 重复：' . $operation);
            $this->byId[$id] = true;
            $this->paths[$route] = true;
            $this->byOperation[$operation] = true;
            $this->endpoints[] = $endpoint;
        }
    }

    public function endpoints(): array { return $this->endpoints; }
    public function find(string $id): ?array
    {
        return isset($this->byId[$id]) ? $this->findById($id) : null;
    }
    public function pathExists(string $path): bool
    {
        foreach ($this->paths as $route => $_) {
            if (str_ends_with((string) $route, ' ' . $path)) return true;
        }
        return false;
    }

    public function match(string $method, string $path): ?array
    {
        foreach ($this->endpoints as $endpoint) {
            if ($endpoint['method'] === $method && $endpoint['path'] === $path) return $endpoint;
        }
        return null;
    }

    public function registerOperation(string $operation, callable $handler): void
    {
        if (isset($this->operations[$operation])) {
            throw new RuntimeException('operation 已有执行者，禁止重复注册：' . $operation);
        }
        $this->operations[$operation] = $handler;
    }

    public function execute(string $operation, array $input): mixed
    {
        if (!isset($this->operations[$operation])) {
            throw new ApiOperationException('操作未实现：' . $operation, 50101);
        }
        return ($this->operations[$operation])($input);
    }

    /** @return list<string> 文档一致性问题清单；空数组表示合规 */
    public function documents(string $root): array
    {
        $problems = [];
        foreach ($this->endpoints as $endpoint) {
            $doc = $root . '/' . $endpoint['doc'];
            if (!is_file($doc)) { $problems[] = $endpoint['id'] . ' 缺少文档：' . $endpoint['doc']; continue; }
            $text = (string) file_get_contents($doc);
            foreach (self::SECTIONS as $section) {
                if (!str_contains($text, $section)) $problems[] = $endpoint['id'] . ' 文档缺少章节：' . $section;
            }
            if (!str_contains($text, $endpoint['id'])) $problems[] = $endpoint['id'] . ' 文档未标注接口编号';
            if (!str_contains($text, $endpoint['path'])) $problems[] = $endpoint['id'] . ' 文档未标注接口路径';
        }
        return $problems;
    }

    public const SECTIONS = [
        '## 1. 接口标识', '## 2. 功能与适用场景', '## 3. 请求规范', '## 4. 响应规范',
        '## 5. 错误码', '## 6. 调用示例', '## 7. 幂等性与副作用', '## 8. 变更记录',
    ];

    private function findById(string $id): ?array
    {
        foreach ($this->endpoints as $endpoint) {
            if ($endpoint['id'] === $id) return $endpoint;
        }
        return null;
    }
}
