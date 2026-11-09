<?php
declare(strict_types=1);
namespace Wx\Contracts;

/**
 * 内容契约 —— 博客正文数据模型（第 11 个系统层对外暴露的能力）。
 *
 * 核心只定义接口：表结构、SQL、分页语义、字段校验全部归 systems/Content 自持。
 * 换成任意存储（MySQL / 远程 API / 静态文件）只需重新绑定本契约，主题、插件、核心零改动。
 */
interface ContentRepositoryInterface
{
    /**
     * 游标分页列表。$query 支持 cursor(string) / limit(int) / status(string) / keyword(string)。
     * @param array<string, mixed> $query
     * @return array{items: list<array<string, mixed>>, next_cursor: ?string, total: int}
     */
    public function list(array $query = []): array;

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array;

    /** @param array<string, mixed> $post @return int 新记录 ID */
    public function create(array $post): int;

    /**
     * 按 slug 更新指定字段，未传的字段保持不变。
     * 返回是否发生了实际写入；字段白名单与状态校验归实现方（本契约不规定具体字段）。
     * @param array<string, mixed> $fields
     */
    public function update(string $slug, array $fields): bool;

    /** 已发布文章总数 */
    public function count(string $status = 'published'): int;
}
