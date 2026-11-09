<?php
declare(strict_types=1);
namespace Wx\Systems\Data;

use InvalidArgumentException;

/**
 * 关系登记簿 —— 数据层只认识「子表.列 → 父表.列」这个形状，不认识业务含义。
 *
 * 登记动作由表的归属系统在 boot() 里完成，所以「comments 的 parent_id 指向 comments」
 * 这条知识仍然留在 comment 层，data 层只是执行者。这是避免数据层变成上帝对象的关键。
 */
final class RelationRegistry
{
    /** @var array<string, array{child: string, column: string, parent: string, key: string, zero: bool}> */
    private array $relations = [];

    public function declare(
        string $name,
        string $child,
        string $column,
        string $parent,
        string $key = 'id',
        bool $zeroIsNull = false
    ): void {
        if (!preg_match('/^[a-z0-9_.]+$/', $name) || !preg_match('/^[a-z0-9_]+$/', $child)
            || !preg_match('/^[a-z0-9_]+$/', $column) || !preg_match('/^[a-z0-9_]+$/', $parent)
            || !preg_match('/^[a-z0-9_]+$/', $key)) {
            throw new InvalidArgumentException('关系名与表名只允许小写字母、数字与下划线');
        }
        $this->relations[$name] = [
            'child' => $child, 'column' => $column, 'parent' => $parent, 'key' => $key, 'zero' => $zeroIsNull,
        ];
    }

    /** @return array<string, array{child: string, column: string, parent: string, key: string, zero: bool}> */
    public function all(): array { return $this->relations; }

    public function count(): int { return count($this->relations); }
}
