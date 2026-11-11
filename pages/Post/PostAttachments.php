<?php
declare(strict_types=1);
namespace Wx\Pages\Post;

use Wx\Contracts\ApiRegistryInterface;

/**
 * 文章附件 —— 只从既有 attachments.list 取数，按 slug 列出可下载的附件。
 *
 * 三态必须可区分：
 *  - 没有附件：items 为空 + state=null（页面写「本文没有附件」）；
 *  - 读取失败：state=unavailable + 原因（页面写「附件信息暂时读不到」，不冒充「没有附件」）；
 *  - 有附件：items 非空。
 *
 * 以匿名身份取列表：私有附件由系统层按可见性过滤掉 —— 这是有意的，
 * 文章页是公开读路径，不能因为「作者登录了」就把私有附件暴露给读者。
 */
final class PostAttachments
{
    public const MAX = 20;

    /** @return array{items: list<array<string, mixed>>, total: int, state: string|null, message: string} */
    public static function load(ApiRegistryInterface $registry, string $slug): array
    {
        if ($slug === '') return self::empty();
        try {
            $r = $registry->execute('attachments.list', ['post_slug' => $slug, '_identity' => null]);
        } catch (\Throwable $e) {
            return ['items' => [], 'total' => 0, 'state' => 'unavailable', 'message' => $e->getMessage()];
        }
        $items = [];
        foreach ((array) ($r['items'] ?? []) as $row) {
            if (!is_array($row) || count($items) >= self::MAX) continue;
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) continue;
            $items[] = [
                'name'  => (string) ($row['name'] ?? ('附件 #' . $id)),
                'url'   => (string) ($row['url'] ?? ('/attach/download?id=' . $id)),
                'ext'   => (string) ($row['ext'] ?? ''),
                'bytes' => (int) ($row['bytes'] ?? 0),
                'sha256' => (string) ($row['sha256'] ?? ''),
            ];
        }
        return ['items' => $items, 'total' => count($items), 'state' => null, 'message' => ''];
    }

    /** @return array{items: list<array<string, mixed>>, total: int, state: string|null, message: string} */
    private static function empty(): array
    {
        return ['items' => [], 'total' => 0, 'state' => null, 'message' => ''];
    }
}
