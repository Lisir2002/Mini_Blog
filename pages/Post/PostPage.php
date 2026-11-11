<?php
declare(strict_types=1);
namespace Wx\Pages\Post;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Contracts\PackageInterface;
use Wx\Contracts\SecurityGuardInterface;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;

/**
 * 文章页 —— 正文 + 评论树 + 评论/互动表单，全部走结构化数据，不产出一个标签。
 *
 * 评论与互动提交走「表单 → 同页 POST → 303 回文章页」的 PRG 路径，不依赖 JavaScript。
 * CSRF 一次性令牌来自安全系统，与后台表单同一套；表单与 JSON 接口共用同一批执行者，
 * 不存在两套校验规则。失败态显式渲染 state（带真实错误码），绝不 303 假装成功。
 */
final class PostPage implements PackageInterface
{
    public function slug(): string { return 'post'; }

    public function register(Kernel $kernel): void
    {
        $kernel->hooks()->on('route.resolve', function (Request $request, Response $response) use ($kernel): void {
            if ($request->path() !== '/post') return;
            $registry = $this->registry($kernel);
            if ($registry === null) return;

            // 稳定匿名主体：优先复用表单/Cookie 里已签发的标识，首次访问才生成并下发。
            // 只生成一次，否则同一读者每次刷新都会换主体，点赞状态永远对不上。
            [$visitor, $fresh] = PostInteraction::visitorFrom(
                $request, (string) ($request->body()['visitor_id'] ?? '')
            );
            if ($fresh) {
                $response->setHeader('Set-Cookie', PostInteraction::cookieHeader($visitor));
            }

            $slug = trim((string) ($request->query()['slug'] ?? $request->body()['slug'] ?? ''));
            if ($request->method() === 'POST') {
                $this->submit($kernel, $registry, $request, $response, $slug, $visitor);
                return;
            }
            $this->view($kernel, $registry, $request, $response, $slug, $visitor);
        }, 10);
    }

    private function view(Kernel $kernel, ApiRegistryInterface $registry, Request $request,
        Response $response, string $slug, string $visitor): void
    {
        $post = $this->post($registry, $slug);
        if ($post === null) {
            $response->setStatus(404)->setLayout('post')->setPayload([
                'title' => '文章不存在', 'page' => 'post', 'post' => null,
                'comments' => ['total' => 0, 'items' => [], 'tree' => []],
                'csrf' => $this->csrf($kernel), 'visitor_id' => $visitor, 'state' => null,
            ]);
            return;
        }
        // 读路径带守卫已裁决的身份：否则 actorKey 恒为空、作者自己的评论也标成不可编辑；
        // 写路径仍用匿名主体（subject），页面对非 GET 不读 Cookie 的边界不变。
        $comments = PostComments::load($kernel, $registry, $request, $slug, $visitor);
        $response->setLayout('post')->setPayload([
            'title'       => (string) ($post['title'] ?? '文章'),
            'page'        => 'post',
            'post'        => $post,
            'comments'    => $comments,
            'attachments' => PostAttachments::load($registry, $slug),
            'csrf'       => $this->csrf($kernel),
            'visitor_id' => $visitor,
            'state'      => null,
        ]);
    }

    private function submit(Kernel $kernel, ApiRegistryInterface $registry, Request $request,
        Response $response, string $slug, string $visitor): void
    {
        $body = $request->body();
        // 失败态会走 setPayload 重新渲染；4xx 会被 render.layout 跳过路径映射，
        // 不自己固定布局就会被默认 site 槽位渲染成「带门户导航的空页面」。
        $response->setLayout('post');
        if (!$this->csrfOk($kernel, (string) ($body['_token'] ?? ''))) {
            $this->reject($kernel, $registry, $request, $response, $slug, $visitor,
                403, 40301, '表单已过期，请刷新后重试');
            return;
        }

        // 编辑意图先于 intent() 分流：POST 没有 Authorization 头，身份来自 GET 时签发的票据。
        $edit = PostEdit::handle($kernel, $registry, $body);
        if ($edit !== null) {
            if (($edit['ok'] ?? false) === true) {
                $response->setStatus(303)
                    ->setHeader('Location', '/post?slug=' . rawurlencode($slug) . '#comments');
                return;
            }
            $this->reject($kernel, $registry, $request, $response, $slug, $visitor,
                (int) ($edit['status'] ?? 422), (int) ($edit['code'] ?? 42201),
                (string) ($edit['message'] ?? '编辑未完成'));
            return;
        }

        try {
            $intent = PostInteraction::intent($body);
            $spec = PostInteraction::interaction($intent, $body);
            if ($spec === null) {
                $input = [
                    'post_slug' => $slug,
                    'body'      => (string) ($body['body'] ?? ''),
                    'author'    => (string) ($body['author'] ?? ''),
                ] + PostInteraction::subject($request, $visitor);
                $parentId = (int) ($body['parent_id'] ?? 0);
                if ($parentId > 0) $input['parent_id'] = $parentId;
                $registry->execute($intent === 'reply' ? 'comments.reply' : 'comments.add', $input);
            } else {
                $registry->execute($spec['operation'],
                    $spec['input'] + PostInteraction::subject($request, $visitor));
            }
        } catch (\Throwable $e) {
            $f = PostInteraction::failure($e);
            $this->reject($kernel, $registry, $request, $response, $slug, $visitor,
                $f['status'], $f['code'], $f['message']);
            return;
        }
        // POST-Redirect-GET：刷新不会重复提交，浏览器地址栏也不会停在 POST 结果上。
        // 成功与否不写进 URL（那会变成可伪造的「假成功」）；重定向后列表里的
        // likes / liked 由真实台账回显，这才是反馈。
        $response->setStatus(303)->setHeader('Location', '/post?slug=' . rawurlencode($slug) . '#comments');
    }

    private function reject(Kernel $kernel, ApiRegistryInterface $registry, Request $request,
        Response $response, string $slug, string $visitor,
        int $status, int $code, string $message): void
    {
        $post = $this->post($registry, $slug);
        $comments = PostComments::load($kernel, $registry, $request, $slug, $visitor);
        $response->setStatus($status)->setLayout('post')->setPayload([
            'title'       => (string) ($post['title'] ?? '文章'),
            'page'        => 'post',
            'post'        => $post,
            'comments'    => $comments,
            'attachments' => PostAttachments::load($registry, $slug),
            'csrf'       => $this->csrf($kernel),
            'visitor_id' => $visitor,
            'state'      => ['phase' => 'failed', 'code' => $code, 'title' => '操作未完成',
                'message' => $message, 'hint' => '请按提示调整后重试；页面已重新载入最新状态。'],
        ]);
    }

    /** @return array<string, mixed>|null */
    private function post(ApiRegistryInterface $registry, string $slug): ?array
    {
        if ($slug === '') return null;
        try {
            return (array) $registry->execute('content.post', ['slug' => $slug, '_identity' => null]);
        } catch (\Throwable) {
            return null;
        }
    }

    private function registry(Kernel $kernel): ?ApiRegistryInterface
    {
        return $kernel->container()->has(ApiRegistryInterface::class)
            ? $kernel->container()->resolve(ApiRegistryInterface::class) : null;
    }

    private function csrf(Kernel $kernel): string
    {
        return $kernel->container()->has(SecurityGuardInterface::class)
            ? $kernel->container()->resolve(SecurityGuardInterface::class)->token() : '';
    }

    private function csrfOk(Kernel $kernel, string $token): bool
    {
        return $token !== '' && $kernel->container()->has(SecurityGuardInterface::class)
            && $kernel->container()->resolve(SecurityGuardInterface::class)->verify($token);
    }
}
