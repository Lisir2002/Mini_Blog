<?php
declare(strict_types=1);
namespace Wx\Tools\Faults;

use Wx\Systems\Api\IdempotencyStore;
use Wx\Tools\Journey\JourneyState;
use Wx\Tools\Journey\Probe;
use Wx\Tools\Journey\Site;

/**
 * 身份与幂等面故障 —— 令牌过期、同键请求还在处理中。
 *
 * 令牌过期必须与「未登录」区分：40101 让客户端去登录，40102 让它刷新令牌，
 * 混成一个码就只能弹登录框，把「自动续期」这条路堵死。
 *
 * 幂等这一组守的是 v0.20 那条「先占位后回填」的两个边角：
 * 占位期间同键请求要被告知稍后重试（40905 + Retry-After），而不是再执行一遍副作用；
 * 执行失败必须释放占位，否则客户端重试会永久撞墙。
 */
final class AuthFaults
{
    public function run(Probe $p, Site $site, JourneyState $s): void
    {
        $auth = ['Authorization' => 'Bearer ' . $s->bearer];

        $p->step('令牌过期：401 + 40102，与「未登录」区分开', function () use ($p, $site, $auth, $s): void {
            $before = $p->get($site->fresh(), '/api/v1/users/me', [], $auth);
            $p->ok($before['status'] === 200, '注入前接口就不通：HTTP ' . $before['status']);

            $this->setExpiry($site, $s->bearer, '2000-01-01 00:00:00');
            $after = $p->get($site->fresh(), '/api/v1/users/me', [], $auth);
            $p->ok($after['status'] === 401, '过期令牌未被拒绝，实际 HTTP ' . $after['status']);
            $p->ok((int) ($after['json']['code'] ?? 0) === 40102,
                '错误码应为 40102（令牌过期），实际 ' . ($after['json']['code'] ?? '?'));

            $this->setExpiry($site, $s->bearer, '2099-01-01 00:00:00');
            $again = $p->get($site->fresh(), '/api/v1/users/me', [], $auth);
            $p->ok($again['status'] === 200, '恢复有效期后仍不通，说明过期判定以外还有东西坏了');
            $p->note('过期 → 401/40102；恢复有效期 → 200（同一条令牌，只改 expires_at）');
        });

        $p->step('同键进行中：40905 + Retry-After 1，不重复执行副作用', function () use ($p, $site, $auth): void {
            $live = $site->fresh();
            $store = new IdempotencyStore($site->dir());
            $key = 'fault-' . bin2hex(random_bytes(4));
            // 必须通过守卫的参数校验才会走到幂等分支（缺字段会在 claim 之前被 42201 拒掉），
            // 因此占位用的指纹必须按「合法 payload」计算。
            $payload = ['title' => 'inflight probe', 'slug' => 'fault-inflight', 'body' => 'x'];
            // 与 ApiDispatcher::fingerprint 完全一致：默认 json_encode，不带额外选项。
            $fp = hash('sha256', 'POST /api/v1/content/posts|' . json_encode([[], [], $payload]));
            $claim = $store->claim($key, $fp);
            $p->ok(($claim['state'] ?? '') === 'claimed',
                '占位未成功：' . json_encode($claim, JSON_UNESCAPED_UNICODE));

            $busy = $p->post($live, '/api/v1/content/posts', $payload, [], $auth + ['Idempotency-Key' => $key]);
            $p->ok($busy['status'] === 409, '同键进行中应返回 409，实际 ' . $busy['status']);
            $p->ok((int) ($busy['json']['code'] ?? 0) === 40905,
                '错误码应为 40905，实际 ' . ($busy['json']['code'] ?? '?'));
            $p->ok(($busy['headers']['Retry-After'] ?? '') === '1', '缺少 Retry-After: 1');

            // 占位仍在：副作用没有被执行第二遍。
            $created = $p->get($site->fresh(), '/api/v1/content/post', ['slug' => 'fault-inflight']);
            $p->ok($created['status'] === 404, '占位期间业务竟然执行了（文章已存在）');

            $store->release($key);
            $done = $p->post($site->fresh(), '/api/v1/content/posts', $payload, [],
                $auth + ['Idempotency-Key' => $key]);
            $p->ok($done['status'] === 200, '释放占位后同键仍不可用：' . substr($done['body'], 0, 160));
            $p->note('pending 占位 → 409/40905 + Retry-After: 1（业务未执行）；release 后同键 200');
        });

        $p->step('业务失败按幂等语义重放：同一键拿到同一结论，不重新执行', function () use ($p, $site, $auth): void {
            $live = $site->fresh();
            $store = new IdempotencyStore($site->dir());
            $key = 'fault-' . bin2hex(random_bytes(4));

            // 评论指向不存在的文章：通过守卫校验，在 execute 阶段以 40403 失败。
            $payload = ['post_slug' => 'no-such-post-fault', 'author' => 'probe', 'body' => 'hi'];
            $bad = $p->post($live, '/api/v1/comments', $payload, [], $auth + ['Idempotency-Key' => $key]);
            $p->ok($bad['status'] === 404, '应返回 404，实际 ' . $bad['status']);
            $p->ok((int) ($bad['json']['code'] ?? 0) === 40403,
                '错误码应为 40403，实际 ' . ($bad['json']['code'] ?? '?'));

            $replay = $p->post($site->fresh(), '/api/v1/comments', $payload, [],
                $auth + ['Idempotency-Key' => $key]);
            $p->ok((int) ($replay['json']['code'] ?? 0) === 40403, '重放结果应与首次一致');
            $p->ok(($replay['headers']['Idempotency-Replayed'] ?? '') === 'true',
                '缺少 Idempotency-Replayed: true，客户端无法区分「重放」与「重新执行」');
            $p->ok($store->get($key) !== null, '业务失败应固化为可重放结论');

            // 只有基础设施故障（50001 / 50101）才释放占位，让客户端能换条件重试；
            // 业务失败（4xx）是确定结论，重放比重新执行更安全。该分支由 27a 守卫覆盖。
            $p->note('业务失败 → complete/replay（40403 + Idempotency-Replayed）；仅 50001/50101 才 release');
        });
    }

    private function setExpiry(Site $site, string $bearer, string $at): void
    {
        $in = $site->databaseInput();
        $pdo = new \PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $in['host'], $in['port'], $in['database']), $in['user'], $in['pass']);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare('UPDATE `' . $site->prefix() . 'user_tokens` SET expires_at = ? WHERE token_hash = ?');
        $stmt->execute([$at, hash('sha256', $bearer)]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('令牌行未被更新（rowCount=' . $stmt->rowCount() . '），故障注入失败');
        }
    }
}
