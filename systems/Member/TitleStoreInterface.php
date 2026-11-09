<?php
declare(strict_types=1);
namespace Wx\Systems\Member;

/**
 * 头衔目录存储协议。
 *
 * 头衔是「站长定义、授予用户」的纯身份标签：它不进入权限决策，也不改变角色集合。
 * 这是刻意的边界 —— 一旦头衔能提权，站长在后台点一个徽章就等于授权，
 * 权限系统会失去唯一的授权入口。
 */
interface TitleStoreInterface
{
    public function role(): string;

    /** 首次可用的示例头衔；已有数据不覆盖。 */
    public function seed(): void;

    /** @return list<array<string, mixed>> 按 priority 降序、title_key 升序 */
    public function titles(bool $includeHidden = true): array;

    public function upsertTitle(array $title): bool;

    public function deleteTitle(string $key): bool;

    /** 手动授予（不写权限表，仅写头衔关系）。 */
    public function grantTitle(string $userId, string $key, string $grantedBy): bool;

    public function revokeTitle(string $userId, string $key): bool;

    /** @return list<array<string, mixed>> 该用户手动获得的头衔 */
    public function titlesOf(string $userId): array;
}
