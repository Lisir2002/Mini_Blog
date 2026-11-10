<?php
declare(strict_types=1);
namespace Wx\Tools\Journey;

/**
 * 阶段二 · 真实身份与内容：签发令牌 → 文章 → 表情 token → 评论树 → 附件 → 自定义表情。
 *
 * 每一环都消费上一环真实响应里的标识（slug / id / sha256），
 * 且全部走 HTTP 请求管线，不直接调容器方法。
 */
final class ContentStage
{
    public function run(Probe $p, Site $site, JourneyState $s): void
    {
        $live = $site->fresh();

        $p->step('真实身份：用安装的管理员账号签发令牌（非静态测试令牌）', function () use ($p, $live, $s): void {
            $res = $p->post($live, '/api/v1/auth/token',
                ['username' => 'webmaster', 'password' => 'Journey-Pass-2026']);
            $d = $p->data($res, 'auth.token');
            $s->bearer = (string) ($d['token'] ?? '');
            $p->ok(preg_match('/^[0-9a-f]{64}$/', $s->bearer) === 1, '令牌不是 64 位十六进制');
            $p->ok(($d['role'] ?? '') === 'admin' && ($d['token_type'] ?? '') === 'Bearer', '令牌身份不正确');
            $p->note('auth.token → 64 位令牌 · role=admin · 存于数据库令牌表');
        });

        // step 是立即执行的，令牌在上一步已写入 $s；闭包按对象捕获即可拿到最新值。
        $auth = static fn(): array => ['Authorization' => 'Bearer ' . $s->bearer];

        $p->step('受保护写接口：匿名 401，真令牌放行', function () use ($p, $live, $auth): void {
            $anon = $p->post($live, '/api/v1/content/posts', ['title' => '匿名', 'body' => 'x']);
            $p->ok($anon['status'] === 401, '匿名写未被拒绝，实际 ' . $anon['status']);
        });

        $p->step('文章：写入含表情短码的正文，读回 body_tokens（数据不是 HTML）', function () use ($p, $live, $auth, $s): void {
            $created = $p->data($p->post($live, '/api/v1/content/posts', [
                'title' => '旅程文章', 'slug' => 'journey-post',
                'body' => '你好 :smile: 世界 :tada:',
            ], [], $auth()), 'content.create');
            $s->postSlug = (string) ($created['slug'] ?? '');
            $p->ok($s->postSlug === 'journey-post', '文章 slug 未落地');
            $post = $p->data($p->get($live, '/api/v1/content/post', ['slug' => $s->postSlug]), 'content.post');
            $tokens = (array) ($post['body_tokens'] ?? []);
            $codes = array_column($tokens, 'code');
            $p->ok(in_array('smile', $codes, true) && in_array('tada', $codes, true), '正文表情未解析成 token');
            $p->ok(!str_contains(json_encode($tokens, JSON_UNESCAPED_UNICODE), '<'), '系统层不应产出 HTML');
            $p->note('slug=journey-post · body_tokens 含 smile/tada（' . count($tokens) . ' 个 token）');
        });

        $p->step('评论树：根评论 → 回评 → 列表里挂上子节点', function () use ($p, $live, $auth, $s): void {
            $root = $p->data($p->post($live, '/api/v1/comments', [
                'post_slug' => $s->postSlug, 'author' => '访客', 'body' => '写得很好 :thumbsup:',
            ], [], $auth()), 'comments.add');
            $s->rootCommentId = (int) ($root['id'] ?? 0);
            $p->ok($s->rootCommentId > 0 && ($root['depth'] ?? -1) === 0, '根评论未落地');
            $reply = $p->data($p->post($live, '/api/v1/comments/reply', [
                'post_slug' => $s->postSlug, 'parent_id' => $s->rootCommentId, 'body' => '同意 :tada:',
            ], [], $auth()), 'comments.reply');
            $p->ok(($reply['depth'] ?? 0) === 1, '回评深度应为 1');
            $list = $p->data($p->get($live, '/api/v1/comments', ['post_slug' => $s->postSlug]), 'comments.list');
            $node = null;
            foreach ((array) ($list['tree'] ?? []) as $n) {
                if ((int) ($n['id'] ?? 0) === $s->rootCommentId) $node = $n;
            }
            $p->ok($node !== null && count((array) ($node['children'] ?? [])) === 1, '树中未挂上子回复');
            $p->note('root id=' . $s->rootCommentId . ' depth=0 · reply depth=1 · tree children=1');
        });

        $p->step('附件：二进制上传 → 内容寻址 → 下载字节一致且强制 nosniff', function () use ($p, $live, $auth, $s, $site): void {
            $s->png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
            $up = $p->data($p->post($live, '/attach/upload', $s->png,
                ['name' => 'ship.png', 'post_slug' => $s->postSlug], $auth()), 'attachments.upload');
            $s->attachmentId = (int) ($up['id'] ?? 0);
            $s->attachmentSha = (string) ($up['sha256'] ?? '');
            $p->ok($s->attachmentId > 0 && strlen($s->attachmentSha) === 64, '上传未返回 id / sha256');
            $p->ok(($up['mime'] ?? '') === 'image/png', 'MIME 应由服务端推导');
            $sha = $s->attachmentSha;
            $file = $site->dir() . '/storage/attachments/' . substr($sha, 0, 2) . '/'
                  . substr($sha, 2, 2) . '/' . $sha;
            $p->ok(is_file($file), '未按哈希分片落盘');
            $down = $p->get($live, '/attach/download', ['id' => $s->attachmentId], $auth());
            $p->ok($down['status'] === 200 && $down['body'] === $s->png, '下载字节与上传不一致');
            $p->ok(($down['headers']['X-Content-Type-Options'] ?? '') === 'nosniff', '缺少 nosniff');
            $p->note('id=' . $s->attachmentId . ' · sha256=' . substr($sha, 0, 12) . '… · 下载 ' . strlen($down['body']) . ' 字节一致');
        });

        $p->step('自定义表情：引用本站附件，图片 token 只指向站内路径', function () use ($p, $live, $auth, $s): void {
            $reg = $p->data($p->post($live, '/api/v1/emojis', [
                'code' => 'shipit', 'attachment_id' => $s->attachmentId,
            ], [], $auth()), 'emojis.register');
            $p->ok(($reg['kind'] ?? '') === 'image' && ($reg['created'] ?? false) === true, '自定义表情未落地');
            $list = $p->data($p->get($live, '/api/v1/emojis'), 'emojis.list');
            $p->ok(in_array('shipit', array_column((array) ($list['items'] ?? []), 'code'), true), '清单里找不到自定义表情');
            $with = $p->data($p->post($live, '/api/v1/content/posts', [
                'title' => '带图', 'slug' => 'emoji-post', 'body' => '发射 :shipit: 完成',
            ], [], $auth()), 'content.create');
            $p->ok((string) ($with['slug'] ?? '') === 'emoji-post', '第二篇文章未落地');
            $post = $p->data($p->get($live, '/api/v1/content/post', ['slug' => 'emoji-post']), 'content.post');
            $image = null;
            foreach ((array) ($post['body_tokens'] ?? []) as $t) {
                if (($t['code'] ?? '') === 'shipit') $image = $t;
            }
            $p->ok($image !== null && ($image['kind'] ?? '') === 'image', '自定义表情未解析成 image token');
            $p->ok(str_starts_with((string) ($image['value'] ?? ''), '/attach/download?id='), '图片表情只能指向站内路径');
            $p->note('code=shipit · kind=image · value=/attach/download?id=' . $s->attachmentId);
        });

        $p->step('列表批量解析：content.posts 一次取全，仍带 body_tokens', function () use ($p, $live): void {
            $list = $p->data($p->get($live, '/api/v1/content/posts', ['limit' => 10]), 'content.posts');
            $items = (array) ($list['items'] ?? []);
            $p->ok(count($items) >= 2, '列表文章数不足');
            $withTokens = 0;
            foreach ($items as $item) if (isset($item['body_tokens'])) $withTokens++;
            $p->ok($withTokens === count($items), '批量呈现漏了 body_tokens');
            $p->note('content.posts items=' . count($items) . ' · 全部携带 body_tokens');
        });
    }
}
