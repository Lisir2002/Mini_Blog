<?php
declare(strict_types=1);
namespace Wx\Systems\Content;

/**
 * 文章行的出站形状 —— 一个转换点，两处读取共用。
 *
 * 存在理由很具体：列表与详情各写一份 normalize，加一个字段时只改一处，
 * 另一处就会「接口文档说有、实际没有」，而这种缺口只在特定页面才暴露。
 */
final class PostRow
{
    /** @param array<string, mixed> $row */
    public static function normalize(array $row): array
    {
        $row['id']          = (int) $row['id'];
        $row['body']        = (string) $row['body'];
        $row['excerpt']     = (string) ($row['excerpt'] ?? self::excerpt((string) $row['body']));
        $row['pinned']      = (int) ($row['pinned'] ?? 0) === 1;
        $row['sort_weight'] = (int) ($row['sort_weight'] ?? 0);
        $row['deleted_at']  = $row['deleted_at'] ?? null;
        $row['publish_at']  = $row['publish_at'] ?? null;
        return $row;
    }

    public static function excerpt(string $body): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($body)));
        return function_exists('mb_substr') ? mb_substr($text, 0, 120) : substr($text, 0, 120);
    }
}
