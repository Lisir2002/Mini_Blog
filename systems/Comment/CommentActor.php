<?php
declare(strict_types=1);
namespace Wx\Systems\Comment;

use InvalidArgumentException;
use Wx\Core\Kernel;

/**
 * 互动主体 —— 把「谁点的赞 / 谁举报的」收敛成一个稳定、不可逆、定长的键。
 *
 * 三个坑必须一起堵住：
 *  1. 登录用户用 user_id，绝不受客户端传来的 visitor_id 影响 —— 否则换个字符串就能刷赞；
 *  2. 匿名优先用不可逆 IP 指纹（与 CommentWriter 同一口径，不存明文 IP）；
 *  3. 两者都拿不到时 **明确拒绝**，不退化成统一的 'anonymous' 键。
 *     退化会让「两个人点赞只算一次」看起来像幂等生效，实则是主体丢失 —— 典型的安静地错。
 *
 * 输出永远 <= 40 字符，与 comment_votes.voter_key 的列宽一致。
 */
final class CommentActor
{
    private const MAX_VISITOR_BYTES = 128;

    public function __construct(private string $root) {}

    public static function fromKernel(Kernel $kernel): self
    {
        return new self($kernel->root());
    }

    /**
     * 宽松解析：拿不到主体时返回 null，不抛异常。
     * 发评论是允许匿名的既有能力 —— 节流不能因为「这次没带 IP」就把写入挡掉，
     * 那会把 CLI / 本机测试 / 反向代理漏透传全部变成 429。
     * @param array<string, mixed>|null $identity 请求守卫注入的 _identity
     * @param string $ip  客户端 IP（可为空：CLI / 反向代理未透传）
     * @param string $visitorId 前端持久化的匿名标识（localStorage / Cookie），可选
     * @return array{key: string, kind: string}|null kind = user | ip | visitor
     */
    public function keyOrNull(?array $identity, string $ip, string $visitorId = ''): ?array
    {
        $uid = (int) ($identity['user_id'] ?? 0);
        if ($uid > 0) return ['key' => 'u:' . $uid, 'kind' => 'user'];

        if ($ip !== '') {
            return ['key' => 'i:' . substr(hash('sha256', $ip . '|' . $this->root), 0, 24), 'kind' => 'ip'];
        }

        $visitor = trim($visitorId);
        if ($visitor === '' || strlen($visitor) > self::MAX_VISITOR_BYTES) return null;
        return ['key' => 'v:' . substr(hash('sha256', $visitor . '|' . $this->root), 0, 24), 'kind' => 'visitor'];
    }

    /**
     * 严格解析：点赞 / 举报的去重必须有主体，拿不到就明确拒绝。
     * @return array{key: string, kind: string}
     */
    public function resolve(?array $identity, string $ip, string $visitorId = ''): array
    {
        $who = $this->keyOrNull($identity, $ip, $visitorId);
        if ($who !== null) return $who;

        $visitor = trim($visitorId);
        if ($visitor !== '' && strlen($visitor) > self::MAX_VISITOR_BYTES) {
            throw new InvalidArgumentException('visitor_id 不能超过 ' . self::MAX_VISITOR_BYTES . ' 字节');
        }
        throw new InvalidArgumentException(
            '无法识别互动主体：请登录后操作，或提供 visitor_id（匿名场景需要稳定的客户端标识）'
        );
    }
}
