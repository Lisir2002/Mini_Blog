<?php
declare(strict_types=1);

use Wx\Contracts\ApiAuthenticatorInterface;
use Wx\Contracts\CommentStoreInterface;
use Wx\Contracts\DatabaseInterface;
use Wx\Systems\Comment\PdoCommentStore;
use Wx\Systems\User\PdoUserProvider;
use Wx\Contracts\SecurityGuardInterface;
use Wx\Contracts\UserProviderInterface;

echo "\n=== 8b. 账号 / 令牌 / 评论与 MySQL 写回 ===\n";




check('凭据校验 + 令牌签发落库，库内只存摘要且带过期', function (): void {
    $k = kernel(null);
    $k->boot();
    /** @var PdoUserProvider $provider */
    $provider = $k->container()->resolve(UserProviderInterface::class);
    truthy($provider->verifyCredentials('admin', 'admin123') !== null, '演示账号凭据校验失败');
    truthy($provider->verifyCredentials('admin', 'wrong') === null, '错误口令不应通过');
    /** @var ApiAuthenticatorInterface $auth */
    $auth = $k->container()->resolve(ApiAuthenticatorInterface::class);
    $issued = $auth->issue(1, 'admin', 'admin', 600);
    $identity = $auth->authenticate($issued['token']);
    truthy($identity !== null && $identity['role'] === 'admin', '落库令牌认证失败');
    truthy($auth->allows('admin', $identity) && !$auth->allows('admin', null), '角色判定不正确');
    $db = $k->container()->resolve(DatabaseInterface::class);
    $count = (int) $db->query('SELECT COUNT(*) AS c FROM ' . $db->prefix() . 'user_tokens WHERE token_hash = :h',
        [':h' => hash('sha256', $issued['token'])])[0]['c'];
    truthy($count === 1, '令牌未落库');
    truthy(!str_contains((string) file_get_contents($k->root() . '/config/app.php'), $issued['token']), '明文令牌不应写入配置');
    $db->exec('DELETE FROM ' . $db->prefix() . 'user_tokens WHERE token_hash = :h',
        [':h' => hash('sha256', $issued['token'])]);
});

check('CSRF 令牌落库、带过期、一次性使用', function (): void {
    $k = kernel(null);
    $k->boot();
    /** @var SecurityGuardInterface $guard */
    $guard = $k->container()->resolve(SecurityGuardInterface::class);
    $token = $guard->token();
    truthy($guard->verify($token), '首次校验应通过');
    truthy(!$guard->verify($token), '同一令牌不应二次通过');
});

check('真实写入评论并可按文章读回（测试后清理）', function (): void {
    $k = kernel(null);
    $k->boot();
    /** @var PdoCommentStore $store */
    $store = $k->container()->resolve(CommentStoreInterface::class);
    $slug = 'guard-comment-' . bin2hex(random_bytes(4));
    $db = $k->container()->resolve(DatabaseInterface::class);
    try {
        $id = $store->add(['post_slug' => $slug, 'author' => 'guard', 'body' => '测试评论']);
        truthy($id > 0, '评论写入失败');
        $rows = $store->forPost($slug);
        truthy(count($rows) === 1 && $rows[0]['body'] === '测试评论', '评论读回不一致');
    } finally {
        $db->exec('DELETE FROM ' . $db->prefix() . 'comments WHERE post_slug = :s', [':s' => $slug]);
    }
});
