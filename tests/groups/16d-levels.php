<?php
declare(strict_types=1);

use Wx\Contracts\DatabaseInterface;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Systems\Member\LevelCurve;

echo "\n=== 16d. 等级系统：门槛边界、只升不降、门槛可改且可解释 ===\n";

/** @return array<string, mixed> */
function levelPost(Kernel $k, string $path, array $payload, array $headers): array
{
    $r = $k->handle(new Request('POST', $path, [], $headers + ['Content-Type' => 'application/json'],
        [], (string) json_encode($payload, JSON_UNESCAPED_UNICODE)));
    return (array) json_decode($r->body(), true);
}

/** @return array<string, mixed> */
function levelGet(Kernel $k, string $path, array $query = []): array
{
    return (array) json_decode($k->handle(new Request('GET', $path, $query))->body(), true);
}

/** @return array<string, mixed> */
function levelKernel(): Kernel
{
    return adminKernel(['api' => [
        'rate_limit' => ['enabled' => false],
        'tokens' => ['test-admin-token' => ['role' => 'admin', 'username' => 'admin', 'user_id' => 1]],
    ]]);
}

function levelPurge(Kernel $k, int $uid): void
{
    $k->boot();
    $db = $k->container()->resolve(DatabaseInterface::class);
    foreach (['member_xp_events', 'member_profiles'] as $table) {
        $db->exec('DELETE FROM ' . $db->prefix() . $table . ' WHERE user_id = :u', [':u' => (string) $uid]);
    }
}

check('等级曲线：门槛边界左闭右开，进度按当前等级区间计算', function (): void {
    $levels = [
        ['level' => 1, 'name' => 'L1', 'min_xp' => 0, 'color' => ''],
        ['level' => 2, 'name' => 'L2', 'min_xp' => 100, 'color' => ''],
        ['level' => 3, 'name' => 'L3', 'min_xp' => 500, 'color' => ''],
    ];
    truthy(LevelCurve::levelOf($levels, 0)['level'] === 1, '0 经验应为 1 级');
    truthy(LevelCurve::levelOf($levels, 99)['level'] === 1, '99 经验应为 1 级');
    truthy(LevelCurve::levelOf($levels, 100)['level'] === 2, '100 经验应为 2 级');
    truthy(LevelCurve::levelOf($levels, 499)['level'] === 2, '499 经验应为 2 级');
    truthy(LevelCurve::levelOf($levels, 500)['level'] === 3, '500 经验应为 3 级');
    $p = LevelCurve::progress($levels, 150);
    truthy($p['next']['level'] === 3 && $p['xp_to_next'] === 350, '进度计算不正确');
    truthy($p['percent'] === 12, '进度百分比应为 12，实际 ' . $p['percent']);
    truthy(LevelCurve::progress($levels, 600)['next'] === null, '满级不应有下一级');
});

check('升级判定：跨门槛时 level_up=true，未跨门槛不加等级', function (): void {
    $k = levelKernel();
    $uid = 9401;
    levelPurge($k, $uid);
    try {
        $first = levelPost($k, '/api/v1/member/xp/award', [
            'user_id' => (string) $uid, 'action' => 'manual.adjust', 'event_key' => 't16d-up-1', 'amount' => 100,
        ], adminHeaders());
        truthy(($first['data']['level'] ?? 0) === 2 && ($first['data']['level_up'] ?? false) === true,
            '跨过 100 经验应升到 2 级');
        $second = levelPost($k, '/api/v1/member/xp/award', [
            'user_id' => (string) $uid, 'action' => 'manual.adjust', 'event_key' => 't16d-up-2', 'amount' => 10,
        ], adminHeaders());
        truthy(($second['data']['level'] ?? 0) === 2 && ($second['data']['level_up'] ?? true) === false,
            '未跨门槛不应再次升级');
    } finally {
        levelPurge($k, $uid);
    }
});

check('只升不降：后续小额经验不会把等级写回低档', function (): void {
    $k = levelKernel();
    $uid = 9402;
    levelPurge($k, $uid);
    try {
        levelPost($k, '/api/v1/member/xp/award', [
            'user_id' => (string) $uid, 'action' => 'manual.adjust', 'event_key' => 't16d-keep-1', 'amount' => 2500,
        ], adminHeaders());
        $db = $k->container()->resolve(DatabaseInterface::class);
        $before = $db->query('SELECT level FROM ' . $db->prefix() . 'member_profiles WHERE user_id = :u',
            [':u' => (string) $uid]);
        truthy((int) ($before[0]['level'] ?? 0) === 4, '应存到 4 级');

        levelPost($k, '/api/v1/member/xp/award', [
            'user_id' => (string) $uid, 'action' => 'manual.adjust', 'event_key' => 't16d-keep-2', 'amount' => 1,
        ], adminHeaders());
        $after = $db->query('SELECT level FROM ' . $db->prefix() . 'member_profiles WHERE user_id = :u',
            [':u' => (string) $uid]);
        truthy((int) ($after[0]['level'] ?? 0) === 4, '小额经验把等级写低了');
    } finally {
        levelPurge($k, $uid);
    }
});

check('门槛可改：重复门槛被拒，删除等级后按相邻等级重新解释', function (): void {
    $k = levelKernel();
    $uid = 9403;
    levelPurge($k, $uid);
    try {
        $dup = levelPost($k, '/api/v1/member/level', ['level' => 9, 'name' => '重复门槛', 'min_xp' => 0, 'color' => ''], adminHeaders());
        truthy(($dup['code'] ?? null) === 42201, '重复门槛未被拒绝');

        levelPost($k, '/api/v1/member/level', ['level' => 3, 'name' => '活跃作者', 'min_xp' => 500, 'color' => '#2fbf71'], adminHeaders());
        levelPost($k, '/api/v1/member/xp/award', [
            'user_id' => (string) $uid, 'action' => 'manual.adjust', 'event_key' => 't16d-hi-1', 'amount' => 600,
        ], adminHeaders());
        $before = levelGet($k, '/api/v1/member/profile', ['user_id' => (string) $uid])['data'] ?? [];
        truthy((int) ($before['level'] ?? 0) === 3, '600 经验应为 3 级');

        // 删除 3 级定义：600 经验应落回 2 级，而不是停留在已不存在的等级
        levelPost($k, '/api/v1/member/level', ['op' => 'delete', 'level' => 3], adminHeaders());
        $after = levelGet($k, '/api/v1/member/profile', ['user_id' => (string) $uid])['data'] ?? [];
        truthy((int) ($after['level'] ?? 0) === 2, '删除等级后未按相邻等级解释');
        // 恢复基线，避免影响其它用例
        levelPost($k, '/api/v1/member/level', ['level' => 3, 'name' => '活跃作者', 'min_xp' => 500, 'color' => '#2fbf71'], adminHeaders());
    } finally {
        levelPurge($k, $uid);
    }
});
