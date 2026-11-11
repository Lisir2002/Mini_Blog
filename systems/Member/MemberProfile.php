<?php
declare(strict_types=1);
namespace Wx\Systems\Member;

use Wx\Core\HookBus;

/**
 * 成员档案组装 —— 只读视图，把「经验余额 + 等级曲线 + 头衔」拼成一份对外快照。
 *
 * 用户名等身份字段不直接查用户表：通过 user.lookup 钩子向用户系统询问，
 * 因此成员系统既不需要认识 user_accounts 表，也能在用户系统替换实现后继续工作。
 */
final class MemberProfile
{
    public function __construct(
        private ProfileStoreInterface $profiles,
        private LevelStoreInterface $levels,
        private TitleBook $titles,
        private HookBus $hooks
    ) {}

    public function role(): string { return 'member_profile'; }

    /**
     * 按 id 或用户名解析用户；解析不出来返回 null（接口层据此返回 40403）。
     * @return array<string, mixed>|null
     */
    public function resolve(string $identifier): ?array
    {
        $identifier = trim($identifier);
        if ($identifier === '') return null;
        $user = $this->hooks->apply('user.lookup', null, $identifier);
        return is_array($user) ? $user : null;
    }

    /** 是否已有成长档案（不等于「用户存在」：账号被删后档案会留下来）。 */
    public function hasProfile(string $userId): bool
    {
        return $this->profiles->profile(trim($userId)) !== null;
    }

    /** @return array<string, mixed> */
    public function of(string $userId): array
    {
        $profile = $this->profiles->profile($userId) ?? [
            'user_id' => $userId, 'xp' => 0, 'level' => 1, 'updated_at' => '',
        ];
        $curve   = LevelCurve::progress($this->levels->levels(), (int) $profile['xp']);
        $level   = $curve['current'];
        $user    = $this->resolve($userId);

        return [
            'user_id'      => $userId,
            'username'     => (string) ($user['username'] ?? ''),
            'display_name' => (string) ($user['display_name'] ?? ''),
            'orphan'       => $user === null,
            'xp'           => (int) $profile['xp'],
            'level'        => (int) $level['level'],
            'level_name'   => (string) $level['name'],
            'level_color'  => (string) $level['color'],
            'next_level'   => $curve['next'] === null ? null : (int) $curve['next']['level'],
            'next_level_name' => $curve['next'] === null ? null : (string) $curve['next']['name'],
            'xp_to_next'   => (int) $curve['xp_to_next'],
            'progress'     => (int) $curve['percent'],
            'titles'       => $this->titles->forUser($userId, (int) $level['level']),
        ];
    }

    /**
     * 排行榜：只暴露公开字段（用户名 / 显示名 / 经验 / 等级），不含角色与邮箱。
     *
     * total 是**有档案的成员总数**，returned 才是本页条数 —— 这两个数一混，
     * 「第 1–20 位 / 共 20 人」就会在站点有 2000 个成员时依然成立，且不报错。
     *
     * @return array{items: list<array<string, mixed>>, total: int, returned: int,
     *               has_more: bool, next_offset: int|null}
     */
    public function leaderboard(int $limit, int $offset): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $total = $this->profiles->count();
        $rows = $this->profiles->leaderboard($limit, $offset);
        $levels = $this->levels->levels();
        $items = [];
        foreach ($rows as $i => $row) {
            $user  = $this->resolve((string) $row['user_id']);
            $level = LevelCurve::levelOf($levels, (int) $row['xp']);
            $items[] = [
                'rank'         => $offset + $i + 1,
                'user_id'      => (string) $row['user_id'],
                'username'     => (string) ($user['username'] ?? ''),
                'display_name' => (string) ($user['display_name'] ?? ''),
                'orphan'       => $user === null,
                'xp'           => (int) $row['xp'],
                'level'        => (int) $level['level'],
                'level_name'   => (string) $level['name'],
            ];
        }
        $returned = count($items);
        $more     = $offset + $returned < $total;
        return ['items' => $items, 'total' => $total, 'returned' => $returned,
            'has_more' => $more, 'next_offset' => $more ? $offset + $returned : null];
    }
}
