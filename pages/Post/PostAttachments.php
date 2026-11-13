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
 * 图片内联预览因此只会指向公开图片，不存在「预览绕过下载授权」的路径。
 *
 * v0.56：契约 attachments.download 自 0.13.0 起就有 disposition=inline
 * （仅 image/ 生效），但前台主题只渲染 <a download>，读者点开图片也是下载。
 * 后台附件库自 v0.43 起有 inline 链接，缺的是读者视角这一处。这里按 MIME
 * 产出 preview_url，主题负责把图片嵌进页面；非图片一律不给预览地址，
 * 免得后端把它强制回退成 attachment 后页面上出现一个骗人的缩略图。
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
            $mime = (string) ($row['mime'] ?? '');
            $items[] = [
                'name'  => (string) ($row['name'] ?? ('附件 #' . $id)),
                'url'   => (string) ($row['url'] ?? ('/attach/download?id=' . $id)),
                'ext'   => (string) ($row['ext'] ?? ''),
                'bytes' => (int) ($row['bytes'] ?? 0),
                'sha256' => (string) ($row['sha256'] ?? ''),
                'mime'  => $mime,
                // 参数名独立成键：既是真实构造的 query，也让参数级账本看得见证据。
                'preview_url' => str_starts_with($mime, 'image/')
                    ? '/attach/download?' . http_build_query(['id' => $id, 'disposition' => 'inline'])
                    : '',
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
