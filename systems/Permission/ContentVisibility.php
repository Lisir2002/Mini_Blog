<?php
declare(strict_types=1);
namespace Wx\Systems\Permission;

use Wx\Contracts\AccessControlInterface;

/**
 * 文章权限协作者 —— 通过 content.visible 钩子过滤列表，内容层不硬依赖权限层。
 *
 * deny 一律剔除；allow 一律放行；abstain（无策略可依）时：
 * published 公开可读，draft / review / archived 仅作者本人可见。
 * 这样「显式拒绝」永远压过「状态默认值」，也不会因缺权限层锁死公开站点。
 *
 * v0.28 起状态收敛为 draft / review / published / archived：旧注释里的
 * private / pending 已不存在，留着会让人按一个查不到的取值去写策略。
 */
final class ContentVisibility
{
    public function __construct(private AccessControlInterface $acl) {}

    public function role(): string { return 'content_visibility'; }

    /**
     * @param list<array<string, mixed>> $posts
     * @param array<string, mixed>|null  $identity
     * @return list<array<string, mixed>>
     */
    public function filter(array $posts, ?array $identity): array
    {
        return array_values(array_filter($posts, function (array $post) use ($identity): bool {
            $resource = [
                'type'   => 'post',
                'id'     => (string) ($post['slug'] ?? ''),
                'status' => (string) ($post['status'] ?? 'published'),
                'author' => (string) ($post['author'] ?? ''),
            ];
            $decision = $this->acl->decide($identity, 'content.read', $resource);
            if ($decision === AccessControlInterface::DENY) return false;
            if ($decision === AccessControlInterface::ALLOW) return true;
            if ($resource['status'] === 'published') return true;
            return $identity !== null
                && (string) ($identity['username'] ?? '') === $resource['author'];
        }));
    }
}
