<?php
declare(strict_types=1);
namespace Wx\Systems\Member;

use InvalidArgumentException;

/**
 * 等级门槛与经验规则的内存实现。
 *
 * 默认值与 PDO 版一致，保证「有库 / 无库」下同一份配置算出同一个等级。
 * 门槛重复同样在写入时拒绝 —— 内存版不因为「只是回退」就放宽语义。
 */
final class MemoryLevelStore implements LevelStoreInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $levels = [];
    /** @var array<string, array<string, mixed>> */
    private array $rules = [];

    public function __construct()
    {
        $this->seed();
    }

    public function role(): string { return 'memory_level_store'; }

    public function seed(): void
    {
        if ($this->levels !== []) return;
        $this->levels = [
            1 => ['level' => 1, 'name' => '初来乍到', 'min_xp' => 0, 'color' => '#9aa4b2'],
            2 => ['level' => 2, 'name' => '常驻读者', 'min_xp' => 100, 'color' => '#4a9eff'],
            3 => ['level' => 3, 'name' => '活跃作者', 'min_xp' => 500, 'color' => '#2fbf71'],
            4 => ['level' => 4, 'name' => '资深成员', 'min_xp' => 2000, 'color' => '#f0a500'],
            5 => ['level' => 5, 'name' => '站点元老', 'min_xp' => 10000, 'color' => '#e0413e'],
        ];
        $this->rules = [
            'post.publish'      => ['action' => 'post.publish', 'amount' => 50, 'daily_cap' => 200, 'enabled' => 1, 'description' => '发布文章'],
            'comment.create'    => ['action' => 'comment.create', 'amount' => 5, 'daily_cap' => 50, 'enabled' => 1, 'description' => '发表评论'],
            'profile.complete'  => ['action' => 'profile.complete', 'amount' => 10, 'daily_cap' => 10, 'enabled' => 1, 'description' => '完善个人资料'],
        ];
    }

    public function levels(): array
    {
        $rows = array_values($this->levels);
        usort($rows, static fn(array $a, array $b): int => $a['min_xp'] <=> $b['min_xp']);
        return $rows;
    }

    public function upsertLevel(int $level, string $name, int $minXp, string $color): bool
    {
        if ($level < 1 || $minXp < 0 || trim($name) === '') {
            throw new InvalidArgumentException('等级需满足：level ≥ 1、min_xp ≥ 0、name 非空');
        }
        foreach ($this->levels as $existing) {
            if ((int) $existing['level'] !== $level && (int) $existing['min_xp'] === $minXp) {
                throw new InvalidArgumentException('等级门槛重复：min_xp=' . $minXp);
            }
        }
        $this->levels[$level] = ['level' => $level, 'name' => trim($name), 'min_xp' => $minXp, 'color' => $color];
        return true;
    }

    public function deleteLevel(int $level): bool
    {
        if (!isset($this->levels[$level])) return false;
        unset($this->levels[$level]);
        return true;
    }

    public function rule(string $action): ?array
    {
        return $this->rules[$action] ?? null;
    }

    public function rules(): array
    {
        $rows = array_values($this->rules);
        usort($rows, static fn(array $a, array $b): int => strcmp($a['action'], $b['action']));
        return $rows;
    }

    public function upsertRule(string $action, int $amount, int $dailyCap, bool $enabled, string $description): bool
    {
        if ($action === '' || $amount <= 0 || $dailyCap < 0) {
            throw new InvalidArgumentException('经验规则需满足：action 非空、amount > 0、daily_cap ≥ 0');
        }
        $this->rules[$action] = [
            'action' => $action, 'amount' => $amount, 'daily_cap' => $dailyCap,
            'enabled' => $enabled ? 1 : 0, 'description' => $description,
        ];
        return true;
    }

    public function deleteRule(string $action): bool
    {
        if (!isset($this->rules[$action])) return false;
        unset($this->rules[$action]);
        return true;
    }
}
