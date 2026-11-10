<?php
declare(strict_types=1);

use Wx\Contracts\ContentRepositoryInterface;
use Wx\Core\Http\Request;
use Wx\Systems\Content\ContentStatus;

/**
 * 36 · 内容生命周期 —— 状态机、回收站、定时发布、分类标签。
 *
 * 这一组要证明的不是「接口能返回 200」，而是三类容易做成假动作的地方真的成立：
 *   1. 状态只能沿矩阵走，拼错与不可达是两种不同的错；
 *   2. 删除之后，列表 / 详情 / 计数 / 存量四条路径同时看不见它；
 *   3. 定时发布到点前，读到的是 404 —— 不是「列表藏起来但知道 slug 就能读」。
 */
echo "\n=== 36. 内容：状态机 / 回收站 / 定时发布 / 分类标签 ===\n";

$site   = tempSite('content-lifecycle');
$prefix = 'cl_';
dropTables($prefix);

/** @return array{0: int, 1: array<string, mixed>} */
$hit = static function ($kernel, string $method, string $path, array $query = [], array $body = []): array {
    $response = $kernel->handle(new Request($method, $path, $query, adminHeaders(), $body));
    return [$response->status(), (array) json_decode($response->body(), true)];
};

try {
    $k = isolatedKernel($site, $prefix);
    $k->boot();
    /** @var ContentRepositoryInterface $repo */
    $repo = $k->container()->resolve(ContentRepositoryInterface::class);

    /** 建一篇草稿并返回 slug。 */
    $draft = static function (string $tag) use ($hit, $k): string {
        $slug = $tag . '-' . bin2hex(random_bytes(3));
        [$s, $b] = $hit($k, 'POST', '/api/v1/content/posts', [],
            ['title' => $tag, 'body' => '正文', 'slug' => $slug, 'status' => 'draft']);
        truthy($s === 200, '建草稿失败：' . $s . ' ' . ($b['message'] ?? ''));
        return $slug;
    };

    check('状态机矩阵穷举：只有矩阵里的边可达，反方向一律拒绝', function (): void {
        $edges = 0;
        $blocked = 0;
        foreach (ContentStatus::ALL as $from) {
            foreach (ContentStatus::ALL as $to) {
                $allowed = $from === $to || in_array($to, ContentStatus::nextOf($from), true);
                truthy(ContentStatus::canTransition($from, $to) === $allowed,
                    '矩阵与判定不一致：' . $from . ' → ' . $to);
                $allowed ? $edges++ : $blocked++;
            }
        }
        // 4 个状态两两组合共 16 对：10 条迁移边 + 4 次同状态幂等 = 14 可达，
        // 只剩 published→review 与 archived→review 两条被禁（已发布内容退回审核没有业务含义）。
        truthy($edges === 14 && $blocked === 2, "矩阵形状变了：可达 {$edges} / 拒绝 {$blocked}");
        truthy(!ContentStatus::canTransition('archived', 'review'), '归档直接进待审应被拒绝');
        truthy(ContentStatus::canTransition('draft', 'draft'), '同状态重复提交应为幂等');
    });

    $slug = $draft('flow');
    check('迁移实测：草稿不可见，review → published 后出现，重复提交幂等', function () use ($hit, $k, $slug): void {
        [$s, $b] = $hit($k, 'GET', '/api/v1/content/posts', ['status' => 'published', 'limit' => 100]);
        truthy(!in_array($slug, array_column($b['data']['items'] ?? [], 'slug'), true), '草稿不应出现在公开列表');

        [$s, $b] = $hit($k, 'POST', '/api/v1/content/post/transition', [], ['slug' => $slug, 'to' => 'review']);
        truthy($s === 200 && ($b['data']['from'] ?? '') === 'draft', 'draft → review 失败：' . $s);
        [$s, $b] = $hit($k, 'POST', '/api/v1/content/post/transition', [], ['slug' => $slug, 'to' => 'review']);
        truthy($s === 200, '同状态重复提交应成功，实际 ' . $s . ' ' . ($b['message'] ?? ''));

        [$s] = $hit($k, 'POST', '/api/v1/content/post/transition', [], ['slug' => $slug, 'to' => 'published']);
        truthy($s === 200, 'review → published 失败：' . $s);
        [$s, $b] = $hit($k, 'GET', '/api/v1/content/posts', ['status' => 'published', 'limit' => 100]);
        truthy(in_array($slug, array_column($b['data']['items'] ?? [], 'slug'), true), '已发布文章不在公开列表');
    });

    check('两种错误分开：拼错状态是 42201，可达性冲突是 40908', function () use ($hit, $k, $slug): void {
        [$s, $b] = $hit($k, 'POST', '/api/v1/content/post/transition', [], ['slug' => $slug, 'to' => 'publiched']);
        truthy($s === 422 && ($b['code'] ?? 0) === 42201, '拼错状态应 42201，实际 ' . $s);
        [$s, $b] = $hit($k, 'POST', '/api/v1/content/post/transition', [], ['slug' => $slug, 'to' => 'review']);
        truthy($s === 409 && ($b['code'] ?? 0) === 40908, 'published → review 应 40908，实际 ' . $s);
    });

    check('比较并设置：状态被并发改走后，基于旧状态的迁移返回 40908', function () use ($repo, $slug): void {
        // 调用方读到的是 published，但另一个人已经把它改成 archived。
        truthy($repo->transition($slug, 'published', 'archived', null), '归档失败');
        truthy($repo->transition($slug, 'published', 'draft', null) === false, '基于过期状态的迁移不应成功');
        truthy((string) $repo->findBySlug($slug)['status'] === 'archived', '状态被后到的请求覆盖');
        truthy($repo->transition($slug, 'archived', 'published', null), '恢复发布失败');
    });

    $sched = $draft('sched');
    check('定时发布：未到点列表与详情都不可见，计数也不含它', function () use ($hit, $k, $repo, $sched): void {
        $before = $repo->count('published');
        [$s, $b] = $hit($k, 'POST', '/api/v1/content/post/transition', [],
            ['slug' => $sched, 'to' => 'published', 'publish_at' => '+2 days']);
        truthy($s === 200 && ($b['data']['scheduled'] ?? false) === true, '排期失败：' . $s);

        [$s, $b] = $hit($k, 'GET', '/api/v1/content/posts', ['status' => 'published', 'limit' => 100]);
        truthy(!in_array($sched, array_column($b['data']['items'] ?? [], 'slug'), true), '未到点的文章出现在列表');
        [$s] = $hit($k, 'GET', '/api/v1/content/post', ['slug' => $sched]);
        truthy($s === 404, '未到点的文章详情应 404，实际 ' . $s);
        truthy($repo->count('published') === $before, '未到点的文章计入了 published 存量');
    });

    check('定时到点：把时间改到过去后立即可见，且不再标记 scheduled', function () use ($hit, $k, $sched): void {
        [$s, $b] = $hit($k, 'POST', '/api/v1/content/post/transition', [],
            ['slug' => $sched, 'to' => 'published', 'publish_at' => '-1 hour']);
        truthy($s === 200 && ($b['data']['scheduled'] ?? true) === false, '到点后仍标记为未生效');
        [$s] = $hit($k, 'GET', '/api/v1/content/post', ['slug' => $sched]);
        truthy($s === 200, '到点后详情仍不可读，实际 ' . $s);
    });

    check('软删除：列表 / 详情 / 存量同时消失，回收站可见，恢复后回到原状态', function () use ($hit, $k, $repo, $sched): void {
        $before = $repo->count('published');
        [$s] = $hit($k, 'POST', '/api/v1/content/post/delete', [], ['slug' => $sched]);
        truthy($s === 200, '删除失败：' . $s);
        truthy($repo->count('published') === $before - 1, '删除后存量未减少');
        truthy($repo->count('trash') === 1, '回收站条数不对');
        [$s] = $hit($k, 'GET', '/api/v1/content/post', ['slug' => $sched]);
        truthy($s === 404, '已删除文章仍可读，实际 ' . $s);

        [$s, $b] = $hit($k, 'GET', '/api/v1/content/trash');
        truthy($s === 200 && in_array($sched, array_column($b['data']['items'] ?? [], 'slug'), true), '回收站看不到');
        [$s, $b] = $hit($k, 'POST', '/api/v1/content/post/delete', [], ['slug' => $sched]);
        truthy($s === 409 && ($b['code'] ?? 0) === 40909, '重复删除应 40909，实际 ' . $s);

        [$s, $b] = $hit($k, 'POST', '/api/v1/content/post/restore', [], ['slug' => $sched]);
        truthy($s === 200 && ($b['data']['status'] ?? '') === 'published', '恢复后状态应保持 published');
        truthy($repo->count('trash') === 0, '恢复后回收站未清空');
        [$s, $b] = $hit($k, 'POST', '/api/v1/content/post/restore', [], ['slug' => $sched]);
        truthy($s === 409 && ($b['code'] ?? 0) === 40910, '重复恢复应 40910，实际 ' . $s);
    });

    check('彻底删除：必须回写 slug，在展示中的文章不能直接抹掉', function () use ($hit, $k, $repo, $sched): void {
        [$s, $b] = $hit($k, 'POST', '/api/v1/content/post/purge', [], ['slug' => $sched, 'confirm' => 'yes']);
        truthy($s === 422 && ($b['code'] ?? 0) === 42201, '错误确认串应 42201，实际 ' . $s);
        [$s, $b] = $hit($k, 'POST', '/api/v1/content/post/purge', [], ['slug' => $sched, 'confirm' => $sched]);
        truthy($s === 409 && ($b['code'] ?? 0) === 40910, '未删除的文章不应可彻底删除，实际 ' . $s);

        $hit($k, 'POST', '/api/v1/content/post/delete', [], ['slug' => $sched]);
        [$s] = $hit($k, 'POST', '/api/v1/content/post/purge', [], ['slug' => $sched, 'confirm' => $sched]);
        truthy($s === 200, '回收站里的文章应可彻底删除，实际 ' . $s);
        truthy($repo->findBySlug($sched, true) === null, '彻底删除后仍能读到');
    });

    check('分类标签：重复创建 40911，绑定幂等，未知术语 40404，筛选与 total 同口径', function () use ($hit, $k, $slug): void {
        [$s, $b] = $hit($k, 'POST', '/api/v1/content/terms/manage', [],
            ['action' => 'create', 'kind' => 'category', 'slug' => 'essay', 'name' => '随笔']);
        truthy($s === 200, '建分类失败：' . $s);
        $termId = (int) ($b['data']['id'] ?? 0);
        [$s, $b] = $hit($k, 'POST', '/api/v1/content/terms/manage', [],
            ['action' => 'create', 'kind' => 'category', 'slug' => 'essay', 'name' => '随笔']);
        truthy($s === 409 && ($b['code'] ?? 0) === 40911, '重复术语应 40911，实际 ' . $s);

        [$s, $b] = $hit($k, 'POST', '/api/v1/content/post/terms', [], ['slug' => $slug, 'terms' => ['category:essay']]);
        truthy($s === 200 && ($b['data']['added'] ?? 0) === 1, '绑定失败：' . $s . ' ' . ($b['message'] ?? ''));
        [$s, $b] = $hit($k, 'POST', '/api/v1/content/post/terms', [], ['slug' => $slug, 'terms' => ['category:essay']]);
        truthy($s === 200 && ($b['data']['added'] ?? -1) === 0 && ($b['data']['removed'] ?? -1) === 0,
            '重复绑定应当零变化，实际 ' . json_encode($b['data'] ?? []));

        [$s, $b] = $hit($k, 'POST', '/api/v1/content/post/terms', [], ['slug' => $slug, 'terms' => ['tag:nope']]);
        truthy($s === 404 && ($b['code'] ?? 0) === 40404, '未知术语应 40404，实际 ' . $s);

        [$s, $b] = $hit($k, 'GET', '/api/v1/content/posts', ['status' => 'published', 'term' => 'essay', 'kind' => 'category']);
        truthy(($b['data']['total'] ?? 0) === 1 && count($b['data']['items'] ?? []) === 1,
            '按术语筛选的 total 与 items 不同口径：' . json_encode([$b['data']['total'] ?? null, count($b['data']['items'] ?? [])]));

        [$s] = $hit($k, 'POST', '/api/v1/content/terms/manage', [], ['action' => 'delete', 'id' => $termId]);
        truthy($s === 200, '删除术语失败：' . $s);
        [$s, $b] = $hit($k, 'GET', '/api/v1/content/post', ['slug' => $slug]);
        truthy(($b['data']['terms'] ?? [1]) === [], '删除术语后文章仍带着它');
    });

    check('内存降级：分类标签明确报 50101，而不是返回空列表', function () use ($site, $hit): void {
        $mem = new \Wx\Core\Kernel($site, ['theme' => ['active' => null], 'database' => ['dsn' => '']]);
        $mem->boot();
        [$s, $b] = $hit($mem, 'GET', '/api/v1/content/terms');
        truthy($s === 501 && ($b['code'] ?? 0) === 50101, '内存模式应报 50101，实际 ' . $s);
    });
} finally {
    dropTables($prefix);
}
