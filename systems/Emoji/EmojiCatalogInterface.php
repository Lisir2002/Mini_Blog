<?php
declare(strict_types=1);
namespace Wx\Systems\Emoji;

/**
 * 表情目录契约 —— 只回答「这个短码对应什么」，不回答「怎么画」。
 *
 * 解析器只依赖本契约，因此表情可以来自内置表、数据库或远端清单，
 * 而评论与文章的渲染代码一行都不用改。
 */
interface EmojiCatalogInterface
{
    /** @return array{code: string, kind: string, value: string, attachment_id: int}|null */
    public function find(string $code): ?array;

    /** 内置 + 自定义的全部条目，按短码排序 @return array<string, array<string, mixed>> */
    public function all(): array;

    /** 仅自定义条目（可删除的那些） @return list<array<string, mixed>> */
    public function custom(): array;

    /** @param array<string, mixed> $emoji @return bool 是否新增成功（短码已存在时返回 false） */
    public function register(array $emoji): bool;

    /** 只允许删除自定义条目；内置短码不可被覆盖或删除 */
    public function remove(string $code): bool;
}
