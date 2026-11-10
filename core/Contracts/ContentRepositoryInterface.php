<?php
declare(strict_types=1);
namespace Wx\Contracts;

/**
 * 内容契约 —— 博客正文数据模型（第 11 个系统层对外暴露的能力）。
 *
 * 核心只定义接口：表结构、SQL、分页语义、字段校验全部归 systems/Content 自持。
 * 换成任意存储（MySQL / 远程 API / 静态文件）只需重新绑定本契约，主题、插件、核心零改动。
 *
 * v0.28 起补齐生命周期：软删除、状态迁移、定时发布。三条不变量写进契约注释，
 * 因为它们是「实现方必须保证」的语义，而不是某一种存储的偶然行为：
 *   1. 列表与计数默认看不到回收站里的文章；
 *   2. `published` 且 `publish_at` 未到点的文章，对外不可见、也不计入 published 计数；
 *   3. 状态迁移是「比较并设置」，并发下最多一个成功。
 */
interface ContentRepositoryInterface
{
    /**
     * 游标分页列表。$query 支持：
     *   cursor(string) / limit(int) / status(string) / keyword(string) /
     *   term(string 术语 slug) / kind(string category|tag)
     * 回收站文章永不出现（用 trash() 读），status=published 时按 publish_at 过滤。
     * @param array<string, mixed> $query
     * @return array{items: list<array<string, mixed>>, next_cursor: ?string, total: int}
     */
    public function list(array $query = []): array;

    /**
     * 回收站列表。参数与 list() 一致，但只返回已软删除的文章。
     * @param array<string, mixed> $query
     * @return array{items: list<array<string, mixed>>, next_cursor: ?string, total: int}
     */
    public function trash(array $query = []): array;

    /**
     * 按 slug 读取。$withDeleted=true 时才返回回收站里的文章 ——
     * 默认读不到，是为了让「删除后还能通过详情页访问」这类漏洞不可能被实现出来。
     */
    public function findBySlug(string $slug, bool $withDeleted = false): ?array;

    /** @param array<string, mixed> $post @return int 新记录 ID */
    public function create(array $post): int;

    /**
     * 按 slug 更新指定字段，未传的字段保持不变。
     * 返回是否发生了实际写入；字段白名单与状态校验归实现方（本契约不规定具体字段）。
     * @param array<string, mixed> $fields
     */
    public function update(string $slug, array $fields): bool;

    /**
     * 比较并设置状态：仅当库里当前状态等于 $from 时才写入 $to。
     *
     * 为什么要传 $from：两个管理员同时把同一篇草稿分别改成「已发布」与「已归档」，
     * 后写入者若无条件覆盖，前一个人的动作就被静默抹掉。返回 false 表示
     * 「当前状态已经不是你看到的那个了」，调用方应回 409 而不是假装成功。
     *
     * $publishAt 为 null 表示立即生效；传入未来时间则状态先变、对外仍不可见，
     * 直到时间到达（由读路径判定，不依赖定时任务）。
     */
    public function transition(string $slug, string $from, string $to, ?string $publishAt = null): bool;

    /** 软删除（进回收站）。已删除的再删返回 false，不重复计时。 */
    public function delete(string $slug): bool;

    /** 从回收站恢复，状态与定时时间保持删除前的样子。不在回收站时返回 false。 */
    public function restore(string $slug): bool;

    /** 彻底删除（不可恢复）。 */
    public function purge(string $slug): bool;

    /**
     * 计数。$status 取值为具体状态 / 'any' / 'trash'：
     * 具体状态与 any 都不含回收站；published 额外排除未到点的定时文章；
     * trash 是回收站条数。
     */
    public function count(string $status = 'published'): int;
}
