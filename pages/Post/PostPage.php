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
 * 文章页 —— 正文 + 评论树 + 评论表单，全部走结构化数据，不产出一个标签。
 *
 * 评论提交走「表单 → 同页 POST → 303 回文章页」的 PRG 路径，不依赖 JavaScript：
 * 评论是核心交互，不该被脚本绑架。CSRF 一次性令牌来自安全系统，与后台表单同一套。
 * 表单与 JSON 接口共用 CommentWriter 的校验与落库，不存在两套规则。
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

            $slug = trim((string) ($request->query()['slug'] ?? $request->body()['slug'] ?? ''));
            if ($request->method() === 'POST') {
                $this->submit($kernel, $registry, $request, $response, $slug);
                return;
            }
            $this->view($kernel, $registry, $response, $slug);
        }, 10);
    }

    private function view(Kernel $kernel, ApiRegistryInterface $registry, Response $response, string $slug): void
    {
        $post = $this->post($registry, $slug);
        if ($post === null) {
            $response->setStatus(404)->setPayload(['title' => '文章不存在', 'page' => 'post', 'missing' => true]);
            return;
        }
        $comments = $registry->execute('comments.list', ['post_slug' => $slug, '_identity' => null]);
        $response->setPayload([
            'title'    => (string) ($post['title'] ?? '文章'),
            'page'     => 'post',
            'post'     => $post,
            'comments' => $comments,
            'csrf'     => $this->csrf($kernel),
            'state'    => null,
        ]);
    }

    private function submit(Kernel $kernel, ApiRegistryInterface $registry, Request $request,
        Response $response, string $slug): void
    {
        $body = $request->body();
        if (!$this->csrfOk($kernel, (string) ($body['_token'] ?? ''))) {
            $this->reject($kernel, $registry, $response, $slug, 40301, '表单已过期，请刷新后重试');
            return;
        }
        $parentId = (int) ($body['parent_id'] ?? 0);
        $input = [
            'post_slug' => $slug,
            'body'      => (string) ($body['body'] ?? ''),
            'author'    => (string) ($body['author'] ?? ''),
            '_identity' => null,
            '_ip'       => (string) ($request->clientIp() ?? ''),
        ];
        try {
            $parentId > 0
                ? $registry->execute('comments.reply', $input + ['parent_id' => $parentId])
                : $registry->execute('comments.add', $input);
        } catch (\Throwable $e) {
            $this->reject($kernel, $registry, $response, $slug, 42201, $e->getMessage());
            return;
        }
        // POST-Redirect-GET：刷新不会重复提交，浏览器地址栏也不会停在 POST 结果上。
        $response->setStatus(303)->setHeader('Location', '/post?slug=' . rawurlencode($slug) . '#comments');
    }

    private function reject(Kernel $kernel, ApiRegistryInterface $registry, Response $response,
        string $slug, int $code, string $message): void
    {
        $post = $this->post($registry, $slug);
        $response->setStatus($code === 40301 ? 403 : 422)->setPayload([
            'title'    => (string) ($post['title'] ?? '文章'),
            'page'     => 'post',
            'post'     => $post,
            'comments' => $registry->execute('comments.list', ['post_slug' => $slug, '_identity' => null]),
            'csrf'     => $this->csrf($kernel),
            'state'    => ['phase' => 'failed', 'code' => $code, 'title' => '评论未提交',
                'message' => $message, 'hint' => '修改后可以直接重试，已填写的内容仍在表单里。'],
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
