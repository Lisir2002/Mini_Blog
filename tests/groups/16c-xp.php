<?php
declare(strict_types=1);

use Wx\Contracts\DatabaseInterface;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;

echo "\n=== 16c. 经验系统：幂等、每日上限、规则启停、事件自动发放 ===\n";

/** 额外带一个「作者 9302」令牌的内核，用于验证内容事件自动发经验。 */
function xpKernel(): Kernel
{
    return adminKernel(['api' => [
        'rate_limit' => ['enabled' => false],
        'tokens' => [
            'test-admin-token' => ['role' => 'admin', 'username' => 'admin', 'user_id' => 1],
            't-xp-author'      => ['role' => 'admin', 'username' => 'xp-author', 'user_id' => 9302],
        ],
    ]]);
}

/** @return array<string, mixed> */
function xpPost(Kernel $k, string $path, array $payload, array $headers): array
{
    $r = $k->handle(new Request('POST', $path, [], $headers + ['Content-Type' => 'application/json'],
        [], (string) json_encode($payload, JSON_UNESCAPED_UNICODE)));
    return (array) json_decode($r->body(), true);
}

/** @return array<string, mixed> */
function xpGet(Kernel $k, string $path, array $query = []): array
{
    return (array) json_decode($k->handle(new Request('GET', $path, $query))->body(), true);
}

function xpPurge(Kernel $k, array $userIds): void
{
    $k->boot();
    $db = $k->container()->resolve(DatabaseInterface::class);
    foreach ($userIds as $id) {
        $db->exec('DELETE FROM ' . $db->prefix() . 'member_xp_events WHERE user_id = :u', [':u' => (string) $id]);
        $db->exec('DELETE FROM ' . $db->prefix() . 'member_profiles WHERE user_id = :u', [':u' => (string) $id]);
    }
}

check('发经验幂等：同一 event_key 重放不加分，余额不变', function (): void {
    $k = xpKernel();
    $uid = 9301;
    xpPurge($k, [$uid]);
    try {
        $first = xpPost($k, '/api/v1/member/xp/award', [
            'user_id' => (string) $uid, 'action' => 'manual.adjust', 'event_key' => 't16c-idem-1', 'amount' => 30,
        ], adminHeaders());
        truthy(($first['data']['applied'] ?? 0) === 30, '首次发放失败');
        $replay = xpPost($k, '/api/v1/member/xp/award', [
            'user_id' => (string) $uid, 'action' => 'manual.adjust', 'event_key' => 't16c-idem-1', 'amount' => 30,
        ], adminHeaders());
        truthy(($replay['data']['duplicate'] ?? false) === true, '重放未被识别为幂等');
        truthy(($replay['data']['applied'] ?? -1) === 0, '重放仍加分');
        truthy(($replay['data']['total'] ?? 0) === 30, '重放后余额变化');
    } finally {
        xpPurge($k, [$uid]);
    }
});

check('每日上限：超出的部分截断，余额只加到上限为止', function (): void {
    $k = xpKernel();
    $uid = 9303;
    $rule = 't16c.capped';
    xpPurge($k, [$uid]);
    try {
        xpPost($k, '/api/v1/member/xp/rule', [
            'action' => $rule, 'amount' => 20, 'daily_cap' => 30, 'enabled' => true, 'description' => '上限测试',
        ], adminHeaders());
        $a = xpPost($k, '/api/v1/member/xp/award', ['user_id' => (string) $uid, 'action' => $rule, 'event_key' => 't16c-cap-1'], adminHeaders());
        $b = xpPost($k, '/api/v1/member/xp/award', ['user_id' => (string) $uid, 'action' => $rule, 'event_key' => 't16c-cap-2'], adminHeaders());
        $c = xpPost($k, '/api/v1/member/xp/award', ['user_id' => (string) $uid, 'action' => $rule, 'event_key' => 't16c-cap-3'], adminHeaders());
        truthy(($a['data']['applied'] ?? 0) === 20, '首笔应为 20');
        truthy(($b['data']['applied'] ?? 0) === 10, '第二笔应被截断为 10');
        truthy(($c['data']['applied'] ?? -1) === 0 && ($c['data']['reason'] ?? '') === 'daily_cap', '第三笔应因上限拒绝');
        truthy(($c['data']['total'] ?? 0) === 30, '余额应停在上限 30');
    } finally {
        xpPost($k, '/api/v1/member/xp/rule', ['op' => 'delete', 'action' => $rule], adminHeaders());
        xpPurge($k, [$uid]);
    }
});

check('规则停用后不再产生经验，但历史余额保留', function (): void {
    $k = xpKernel();
    $uid = 9304;
    $rule = 't16c.disabled';
    xpPurge($k, [$uid]);
    try {
        xpPost($k, '/api/v1/member/xp/rule', ['action' => $rule, 'amount' => 15, 'daily_cap' => 0, 'enabled' => true], adminHeaders());
        xpPost($k, '/api/v1/member/xp/award', ['user_id' => (string) $uid, 'action' => $rule, 'event_key' => 't16c-dis-1'], adminHeaders());
        xpPost($k, '/api/v1/member/xp/rule', ['action' => $rule, 'amount' => 15, 'daily_cap' => 0, 'enabled' => false], adminHeaders());
        $r = xpPost($k, '/api/v1/member/xp/award', ['user_id' => (string) $uid, 'action' => $rule, 'event_key' => 't16c-dis-2'], adminHeaders());
        truthy(($r['data']['reason'] ?? '') === 'disabled', '停用规则未生效');
        truthy(($r['data']['total'] ?? 0) === 15, '停用规则不应清空历史余额');
    } finally {
        xpPost($k, '/api/v1/member/xp/rule', ['op' => 'delete', 'action' => $rule], adminHeaders());
        xpPurge($k, [$uid]);
    }
});

check('事件自动发放：发布文章加经验，同一文章重放不重复，匿名评论不产生经验', function (): void {
    $k = xpKernel();
    $uid = 9302;
    $slug = 't16c-post-' . bin2hex(random_bytes(3));
    xpPurge($k, [$uid]);
    try {
        $created = xpPost($k, '/api/v1/content/posts', [
            'slug' => $slug, 'title' => '经验测试', 'body' => '正文', 'status' => 'draft',
        ], bearer('t-xp-author'));
        truthy(($created['code'] ?? null) === 0, '创建文章失败');
        $id = (int) ($created['data']['id'] ?? 0);

        $profile = xpGet($k, '/api/v1/member/profile', ['user_id' => (string) $uid]);
        truthy(($profile['data']['xp'] ?? 0) === 50, '发布文章应自动获得 50 经验');

        // 再次广播同一内容事实：事件键相同，应被视为重放而不是二次奖励
        $k->hooks()->emit('content.created', $id, ['slug' => $slug], ['user_id' => $uid, 'username' => 'xp-author']);
        $again = xpGet($k, '/api/v1/member/profile', ['user_id' => (string) $uid]);
        truthy(($again['data']['xp'] ?? 0) === 50, '重放事件导致经验重复增加');

        $comment = xpPost($k, '/api/v1/comments', ['post_slug' => $slug, 'body' => '匿名评论'], []);
        truthy(($comment['code'] ?? null) === 0, '匿名评论失败');
        $db = $k->container()->resolve(DatabaseInterface::class);
        $count = $db->query(
            'SELECT COUNT(*) AS c FROM ' . $db->prefix() . 'member_xp_events WHERE event_key = :k',
            [':k' => 'comment:' . (int) ($comment['data']['id'] ?? 0)]
        );
        truthy((int) ($count[0]['c'] ?? 0) === 0, '匿名评论不应产生经验流水');
    } finally {
        $db = $k->container()->resolve(DatabaseInterface::class);
        $db->exec('DELETE FROM ' . $db->prefix() . 'comments WHERE post_slug = :s', [':s' => $slug]);
        $db->exec('DELETE FROM ' . $db->prefix() . 'content_posts WHERE slug = :s', [':s' => $slug]);
        xpPurge($k, [$uid]);
    }
});
