<?php
declare(strict_types=1);
namespace Wx\Tools\Journey;

/**
 * 阶段间共享的事实 —— 上一步真实响应里取到的值，供下一步继续走。
 * 刻意只存「响应产生的标识」，不存推断：下一步必须靠这些标识重新发请求。
 */
final class JourneyState
{
    public string $installToken = '';
    public string $bearer = '';
    public string $postSlug = '';
    public int $rootCommentId = 0;
    public int $attachmentId = 0;
    public string $attachmentSha = '';
    public string $png = '';
    /** @var list<string> */
    public array $visited = [];
}
