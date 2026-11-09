<?php
declare(strict_types=1);
namespace Wx\Systems\Permission;

/**
 * 权限缓存的后端协议 —— 只承载「基础数据读取」的键值存取与全局失效标记。
 *
 * 这不是核心契约：它属于权限系统层的内部扩展点，替换后端不会改变任何决策语义，
 * 也不会让核心多认识一个概念（规矩 D：core/ 不因实现细节新增文件）。
 *
 * epoch 的语义：每次授权写操作都会推进 epoch；任何旧 epoch 下写入的条目立即不可读。
 * 这样跨进程 / 跨请求共享同一份缓存时，失效不需要广播，也不需要逐条删除。
 */
interface CacheBackend
{
    /** 当前失效纪元；后端实现必须保证它廉价可读（每秒多次调用是正常情况） */
    public function epoch(): string;

    /** 命中返回值；未命中或纪元不匹配返回 false */
    public function get(string $key, string $epoch): mixed;

    public function set(string $key, mixed $value, string $epoch): void;

    /** 推进纪元，使所有旧条目立即失效 */
    public function clear(): void;

    /** @return array<string, mixed> 供 permission.decisions 接口与守卫对账 */
    public function describe(): array;
}
