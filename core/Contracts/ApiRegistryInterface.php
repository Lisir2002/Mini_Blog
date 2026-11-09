<?php
declare(strict_types=1);
namespace Wx\Contracts;

/**
 * 接口契约 —— 「程序各项操作规范化为接口」的落点。
 *
 * 接口清单是唯一事实源；每个接口通过 operation 绑定唯一执行者。
 * 核心不认识任何接口路径，也不认识任何业务操作。
 */
interface ApiRegistryInterface
{
    /** @return list<array<string, mixed>> */
    public function endpoints(): array;

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array;

    /** @return array<string, mixed>|null 按 method + path 精确匹配 */
    public function match(string $method, string $path): ?array;

    /** 一个 operation 只允许一个执行者，重复注册直接报错 */
    public function registerOperation(string $operation, callable $handler): void;

    public function execute(string $operation, array $input): mixed;
}
