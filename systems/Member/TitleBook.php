<?php
declare(strict_types=1);
namespace Wx\Systems\Member;

use InvalidArgumentException;

/**
 * 头衔簿 —— 站长定义与授予的用例层。
 *
 * 头衔来源只有两种：站长手动授予，或「等级达到门槛」自动出现。
 * 后者不写授予表，而是在读取时按当前等级并集计算 —— 门槛改高后自动消失，
 * 不需要站长逐人回收；手动授予则必须显式撤销。两种来源都不触碰权限。
 */
final class TitleBook
{
    public function __construct(private TitleStoreInterface $store) {}

    public function role(): string { return 'title_book'; }

    /** @return list<array<string, mixed>> */
    public function definitions(bool $includeHidden = true): array
    {
        return $this->store->titles($includeHidden);
    }

    /** @param array<string, mixed> $title */
    public function upsert(array $title): bool
    {
        return $this->store->upsertTitle($title);
    }

    public function delete(string $key): bool
    {
        return $this->store->deleteTitle(trim($key));
    }

    public function grant(string $userId, string $key, string $grantedBy): bool
    {
        $userId = trim($userId);
        $key    = trim($key);
        if ($userId === '') throw new InvalidArgumentException('user_id 必填');
        return $this->store->grantTitle($userId, $key, $grantedBy);
    }

    public function revoke(string $userId, string $key): bool
    {
        return $this->store->revokeTitle(trim($userId), trim($key));
    }

    /**
     * 某人当前应展示的头衔：手动授予 ∪ 等级自动获得。
     * source 字段让主题可以区分「站长给的」与「自己练出来的」。
     *
     * @return list<array<string, mixed>>
     */
    public function forUser(string $userId, int $level): array
    {
        $merged = [];
        foreach ($this->store->titlesOf($userId) as $title) {
            $title['source'] = 'manual';
            $merged[(string) $title['title_key']] = $title;
        }
        foreach ($this->store->titles(true) as $title) {
            $min = (int) ($title['min_level'] ?? 0);
            if ($min <= 0 || $level < $min) continue;
            $key = (string) $title['title_key'];
            if (!isset($merged[$key])) {
                $title['source'] = 'level';
                $merged[$key] = $title;
            }
        }
        $out = array_values($merged);
        usort($out, static fn(array $a, array $b): int =>
            ((int) $b['priority'] <=> (int) $a['priority']) ?: strcmp((string) $a['title_key'], (string) $b['title_key'])
        );
        return $out;
    }
}
