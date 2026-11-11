<?php
declare(strict_types=1);
namespace Wx\Pages\Post;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Systems\Page\PageAccessGuard;

/**
 * 文章页的评论区装配 —— 一次把「读身份、取列表、补互动布尔、附编辑票据」做完。
 *
 * 抽出来的原因不是分层好看：GET 与失败重渲染必须用同一份上下文。
 * 若只在正常路径带身份，提交失败后重新渲染的页面里编辑入口会整片消失，
 * 用户会以为自己失去了权限。这里是唯一入口，两处都走它。
 */
final class PostComments
{
    /** @return array<string, mixed> */
    public static function load(Kernel $kernel, ApiRegistryInterface $registry,
        Request $request, string $slug, string $visitor): array
    {
        $identity = self::identity($kernel, $request);
        $comments = (array) $registry->execute(
            'comments.list', ['post_slug' => $slug] + PostInteraction::readSubject($request, $visitor, $identity)
        );
        $comments['tree'] = PostInteraction::decorateTree((array) ($comments['tree'] ?? []));

        // 票据只发给「本人且当前可编辑」的节点；签不出来就整片不出现，
        // 不渲染一个点了会报错的按钮。匿名视角连密钥都不碰（不产生无谓的落盘）。
        if ($identity !== null) {
            $signer = CommentEditTicket::fromKernel($kernel->root());
            if ($signer !== null) {
                $comments['tree'] = $signer->decorate((array) $comments['tree'], $identity, time());
            }
        }
        return $comments;
    }

    /**
     * 页面守卫在 route.access 已裁决过身份，这里只取结果，不重新解析令牌。
     * 容器里没有守卫（未装配页面系统）时返回 null —— 匿名视角，不是故障。
     * @return array<string, mixed>|null
     */
    public static function identity(Kernel $kernel, Request $request): ?array
    {
        $container = $kernel->container();
        if (!$container->has(PageAccessGuard::class)) return null;
        $guard = $container->resolve(PageAccessGuard::class);
        return $guard instanceof PageAccessGuard ? $guard->identityFor($request) : null;
    }
}
