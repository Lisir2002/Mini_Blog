<?php
declare(strict_types=1);
namespace Wx\Systems\Member;

use InvalidArgumentException;

/** 头衔目录的内存实现：语义与 PDO 版一致，供无库启动与守卫测试使用。 */
final class MemoryTitleStore implements TitleStoreInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $titles = [];
    /** @var array<string, array<string, array<string, mixed>>> user_id => title_key => 授予信息 */
    private array $grants = [];

    public function __construct()
    {
        $this->seed();
    }

    public function role(): string { return 'memory_title_store'; }

    public function seed(): void
    {
        if ($this->titles !== []) return;
        $this->titles = [
            'founder'     => ['title_key' => 'founder', 'name' => '站长', 'description' => '站点创建者',
                'color' => '#e0413e', 'priority' => 100, 'visible' => true, 'min_level' => 0],
            'contributor' => ['title_key' => 'contributor', 'name' => '贡献者', 'description' => '持续贡献内容或代码',
                'color' => '#2fbf71', 'priority' => 60, 'visible' => true, 'min_level' => 0],
            'veteran'     => ['title_key' => 'veteran', 'name' => '资深成员', 'description' => '等级达到 4 级自动获得',
                'color' => '#f0a500', 'priority' => 50, 'visible' => true, 'min_level' => 4],
        ];
    }

    public function titles(bool $includeHidden = true): array
    {
        $rows = array_values(array_filter(
            $this->titles,
            static fn(array $t): bool => $includeHidden || $t['visible'] === true
        ));
        usort($rows, static fn(array $a, array $b): int =>
            ((int) $b['priority'] <=> (int) $a['priority']) ?: strcmp((string) $a['title_key'], (string) $b['title_key'])
        );
        return $rows;
    }

    public function upsertTitle(array $title): bool
    {
        $key  = trim((string) ($title['title_key'] ?? ''));
        $name = trim((string) ($title['name'] ?? ''));
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $key) || $name === '') {
            throw new InvalidArgumentException('头衔需满足：title_key 为小写标识、name 非空');
        }
        $this->titles[$key] = [
            'title_key' => $key, 'name' => $name,
            'description' => (string) ($title['description'] ?? ''),
            'color' => (string) ($title['color'] ?? ''),
            'priority' => (int) ($title['priority'] ?? 0),
            'visible' => (int) ($title['visible'] ?? 1) === 1,
            'min_level' => max(0, (int) ($title['min_level'] ?? 0)),
        ];
        return true;
    }

    public function deleteTitle(string $key): bool
    {
        if (!isset($this->titles[$key])) return false;
        unset($this->titles[$key]);
        foreach ($this->grants as $userId => $titles) {
            unset($this->grants[$userId][$key]);
        }
        return true;
    }

    public function grantTitle(string $userId, string $key, string $grantedBy): bool
    {
        if ($userId === '' || $key === '') return false;
        if (!isset($this->titles[$key])) {
            throw new InvalidArgumentException('头衔不存在：' . $key);
        }
        if (isset($this->grants[$userId][$key])) return false;
        $this->grants[$userId][$key] = [
            'title_key' => $key, 'granted_by' => $grantedBy, 'granted_at' => date('Y-m-d H:i:s'),
        ];
        return true;
    }

    public function revokeTitle(string $userId, string $key): bool
    {
        if (!isset($this->grants[$userId][$key])) return false;
        unset($this->grants[$userId][$key]);
        return true;
    }

    public function titlesOf(string $userId): array
    {
        $out = [];
        foreach (array_keys($this->grants[$userId] ?? []) as $key) {
            if (isset($this->titles[$key])) $out[] = $this->titles[$key];
        }
        usort($out, static fn(array $a, array $b): int =>
            ((int) $b['priority'] <=> (int) $a['priority']) ?: strcmp((string) $a['title_key'], (string) $b['title_key'])
        );
        return $out;
    }
}
