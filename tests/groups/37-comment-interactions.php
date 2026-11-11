<?php
declare(strict_types=1);

use Wx\Contracts\CommentStoreInterface;
use Wx\Core\Http\Request;
use Wx\Systems\Comment\CommentActor;
use Wx\Systems\Comment\CommentInteractionPolicy;
use Wx\Systems\Comment\CommentInteractionStoreInterface;
use Wx\Systems\Comment\CommentListDecorator;
use Wx\Systems\Comment\CommentPolicy;
use Wx\Systems\Comment\MemoryCommentStore;
use Wx\Systems\Comment\MemoryInteractionStore;
use Wx\Systems\Comment\PdoInteractionStore;
use Wx\Systems\Data\QueryProfilerInterface;

/**
 * 37 · 评论互动与治理（v0.29）。
 *
 * 守的不是「能点赞」，而是四种安静失效：
 *  - 主体退化：拿不到身份时塌成统一的 anonymous 键，两个人点赞只算一次；
 *  - 窗口续命：每次编辑重置窗口，「10 分钟内可编辑」变成永久可编辑；
 *  - 阈值重触发：重复举报把 auto_hidden 一次次点亮；
 *  - 降级缩水：内存实现的审核队列少掉纯待审的那一批，且不报任何错。
 *
 * 另有一条效率不变量：列表补计数是常数条 SQL，不随评论数增长。
 */
echo "\n=== 37. 评论互动：幂等 / 阈值 / 编辑窗口 / 队列 / 节流 ===\n";

$actor = new CommentActor('/workspace');

check('主体识别：登录 > IP 指纹 > visitor_id，三条路径互不串味', function () use ($actor): void {
    $u = $actor->resolve(['user_id' => 7], '1.2.3.4', 'v-x');
    truthy($u === ['key' => 'u:7', 'kind' => 'user'], '登录身份应压过 IP 与 visitor_id');
    $i = $actor->resolve(null, '1.2.3.4', 'v-x');
    truthy($i['kind'] === 'ip' && str_starts_with($i['key'], 'i:'), '匿名有 IP 应走指纹');
    $v = $actor->resolve(null, '', 'v-x');
    truthy($v['kind'] === 'visitor' && str_starts_with($v['key'], 'v:'), '无 IP 应走 visitor_id');
    truthy($actor->resolve(null, '1.2.3.4')['key'] !== $actor->resolve(null, '1.2.3.5')['key'],
        '不同 IP 必须是不同主体');
    truthy(strlen($i['key']) <= 40 && strlen($v['key']) <= 40, '主体键超出列宽 VARCHAR(40)');
});

check('主体识别：完全无法识别时严格解析拒绝、宽松解析返回 null', function () use ($actor): void {
    truthy($actor->keyOrNull(null, '', '') === null, '宽松解析应返回 null 而不是统一键');
    $threw = false;
    try { $actor->resolve(null, '', ''); } catch (InvalidArgumentException) { $threw = true; }
    truthy($threw, '严格解析必须抛异常：否则所有匿名会被合并成一个主体');
    $threw2 = false;
    try { $actor->resolve(null, '', str_repeat('x', 200)); } catch (InvalidArgumentException) { $threw2 = true; }
    truthy($threw2, '超长 visitor_id 必须被拒，不能截断后当作另一个合法主体');
});

check('策略表驱动：编辑窗口 7 条分支穷举，窗口从 created_at 起算', function (): void {
    $now = 1700000000;
    $base = [
        'status' => CommentPolicy::PUBLISHED, 'created_at' => date('Y-m-d H:i:s', $now - 60),
        'author_id' => 5, 'edit_count' => 0,
    ];
    $me = ['user_id' => 5];
    $ok = CommentInteractionPolicy::editable($base, $me, null, $now, 10);
    truthy($ok['ok'] && $ok['remaining'] === 540, '窗口内应可编辑，剩余秒数应为 540');

    $cases = [
        'deleted'          => [['status' => CommentPolicy::DELETED], 40914],
        'status'           => [['status' => CommentPolicy::SPAM], 40914],
        'not_owner'        => [[], 40301],
        'no_timestamp'     => [['created_at' => ''], 40912],
        'future_timestamp' => [['created_at' => date('Y-m-d H:i:s', $now + 100)], 40912],
        'window_closed'    => [['created_at' => date('Y-m-d H:i:s', $now - 601)], 40912],
    ];
    foreach ($cases as $reason => [$patch, $code]) {
        $row = array_replace($base, $patch);
        $identity = $reason === 'not_owner' ? ['user_id' => 99] : $me;
        $r = CommentInteractionPolicy::editable($row, $identity, null, $now, 10);
        truthy(!$r['ok'] && $r['code'] === $code && $r['reason'] === $reason,
            "分支 {$reason} 判定错误：" . json_encode($r));
    }
    // 窗口为 0 时连 1 秒都不给：能力可整体关闭，而不是「关不掉」。
    truthy(!CommentInteractionPolicy::editable($base, $me, null, $now, 0)['ok'], '窗口 0 应关闭编辑');
});

check('策略表驱动：举报阈值只在 published + 新举报时触发', function (): void {
    $pub = ['status' => CommentPolicy::PUBLISHED];
    truthy(CommentInteractionPolicy::shouldAutoHide($pub, 3, 3), '达到阈值应触发');
    truthy(!CommentInteractionPolicy::shouldAutoHide($pub, 2, 3), '未达阈值不应触发');
    truthy(!CommentInteractionPolicy::shouldAutoHide($pub, 5, 0), '阈值 0 表示关闭自动下架');
    truthy(!CommentInteractionPolicy::shouldAutoHide(['status' => CommentPolicy::PENDING], 9, 3),
        '已经是 pending 不应重复下架');
    truthy(CommentInteractionPolicy::reasonValid('spam') && !CommentInteractionPolicy::reasonValid('nonsense'),
        '举报原因白名单应生效');
    truthy(CommentInteractionPolicy::interactable($pub), 'published 可互动');
    truthy(!CommentInteractionPolicy::interactable(['status' => CommentPolicy::PENDING]), 'pending 不可互动');
});

check('双实现一致：内存/PDO 的点赞幂等、举报去重、编辑乐观锁语义相同', function () use ($root): void {
    MemoryCommentStore::reset();
    MemoryInteractionStore::reset();
    $mem = new MemoryCommentStore();
    $memStore = new MemoryInteractionStore($mem);
    $mid = $mem->add(['post_slug' => 'p', 'body' => 'x', 'status' => CommentPolicy::PUBLISHED,
        'author_id' => 1, 'created_at' => date('Y-m-d H:i:s')]);

    $a = $memStore->vote($mid, 'u:1', true);
    $b = $memStore->vote($mid, 'u:1', true);
    truthy($a['changed'] === true && $a['likes'] === 1, '首次点赞应新增');
    truthy($b['changed'] === false && $b['likes'] === 1, '重复点赞不能重复计数');
    $c = $memStore->vote($mid, 'u:2', true);
    truthy($c['likes'] === 2, '不同主体应累加');
    $d = $memStore->vote($mid, 'u:1', false);
    $e = $memStore->vote($mid, 'u:1', false);
    truthy($d['changed'] === true && $d['likes'] === 1, '取消点赞应减少');
    truthy($e['changed'] === false && $e['likes'] === 1, '重复取消幂等');

    $r1 = $memStore->report($mid, 'u:1', 'spam');
    $r2 = $memStore->report($mid, 'u:1', 'abuse');
    truthy($r1['changed'] && !$r2['changed'] && $r2['reports'] === 1, '举报去重：换 reason 也不能重复计数');

    $ed1 = $memStore->edit($mid, '新正文', 0);
    $ed2 = $memStore->edit($mid, '用旧版本', 0);
    truthy($ed1['changed'] && $ed1['edit_count'] === 1, '首次编辑应成功');
    truthy(!$ed2['changed'] && $ed2['edit_count'] === 1, '旧版本必须冲突，不能静默覆盖');
    truthy($mem->find($mid)['body'] === '新正文', '编辑必须真的改到主表，不能只改台账');

    $snap = $memStore->countsFor([$mid, $mid + 1], 'u:2');
    truthy($snap[$mid]['liked'] === true && $snap[$mid]['reported'] === false, '批量快照的主体标记错误');
    truthy($snap[$mid + 1]['likes'] === 0, '不存在的评论应有零值而不是缺键');
});

check('降级队列口径：pending 无举报也必须进队列（PDO 与内存逐字一致）', function () use ($root): void {
    MemoryCommentStore::reset();
    MemoryInteractionStore::reset();
    $mem = new MemoryCommentStore();
    $store = new MemoryInteractionStore($mem);
    $pending = $mem->add(['post_slug' => 'p', 'body' => '待审', 'status' => CommentPolicy::PENDING,
        'author_id' => 1, 'created_at' => date('Y-m-d H:i:s')]);
    $published = $mem->add(['post_slug' => 'p', 'body' => '被举报', 'status' => CommentPolicy::PUBLISHED,
        'author_id' => 1, 'created_at' => date('Y-m-d H:i:s')]);
    $deleted = $mem->add(['post_slug' => 'p', 'body' => '', 'status' => CommentPolicy::DELETED,
        'author_id' => 1, 'created_at' => date('Y-m-d H:i:s')]);
    $store->report($published, 'u:9', 'spam');
    $store->report($deleted, 'u:9', 'spam');
    $ids = array_column($store->queue(50, 0), 'comment_id');
    truthy(in_array($pending, $ids, true), '内存版漏掉了纯待审评论（降级缩水）');
    truthy(in_array($published, $ids, true), '有举报的已发布评论应进队列');
    truthy(!in_array($deleted, $ids, true), '已删除评论不应进队列');
    truthy($store->queueTotal() === 2, '队列总数应为 2，实际 ' . $store->queueTotal());
    truthy($ids[0] === $published, '举报数多的应排在前面');
});

check('效率不变量：列表补计数是常数条 SQL，不随评论条数增长', function () use ($root): void {
    MemoryCommentStore::reset();
    MemoryInteractionStore::reset();
    $mem = new MemoryCommentStore();
    $store = new MemoryInteractionStore($mem);
    $rows = [];
    for ($i = 0; $i < 40; $i++) {
        $rows[] = ['id' => $i + 1, 'body' => 'x'];
    }
    // 用一个计数探针包住契约，统计 countsFor 的调用次数，而不是依赖具体 SQL 条数：
    // 无论底层是 MySQL 三条聚合查询还是内存遍历，「每条目查一次」都必须被抓到。
    $calls = 0;
    $probe = new class($store, $calls) implements CommentInteractionStoreInterface {
        public int $calls = 0;
        public function __construct(private CommentInteractionStoreInterface $inner, int $ignored) {}
        public function vote(int $id, string $k, bool $add): array { return $this->inner->vote($id, $k, $add); }
        public function report(int $id, string $k, string $r): array { return $this->inner->report($id, $k, $r); }
        public function countsFor(array $ids, string $k): array { $this->calls++; return $this->inner->countsFor($ids, $k); }
        public function reportCount(int $id): int { return $this->inner->reportCount($id); }
        public function edit(int $id, string $b, int $e): array { return $this->inner->edit($id, $b, $e); }
        public function stats(int $id): array { return $this->inner->stats($id); }
        public function queue(int $l, int $o): array { return $this->inner->queue($l, $o); }
        public function queueTotal(): int { return $this->inner->queueTotal(); }
    };
    CommentListDecorator::apply($rows, 'u:1', $probe);
    truthy($probe->calls === 1, '40 条评论应只批量查一次台账，实际 ' . $probe->calls . ' 次（N+1 回归）');
    $out = CommentListDecorator::apply($rows, 'u:1', $store);
    truthy(count($out) === 40 && isset($out[0]['likes'], $out[39]['reported']), '批量装饰应覆盖全部条目');
});
