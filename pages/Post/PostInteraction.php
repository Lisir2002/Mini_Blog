<?php
declare(strict_types=1);
namespace Wx\Pages\Post;

use InvalidArgumentException;
use Wx\Core\Http\Request;
use Wx\Systems\Api\ApiOperationException;
use Wx\Systems\Comment\CommentInteractionPolicy;

/**
 * 文章页互动协作者 —— 把「表单意图 → 接口入参 / 主体上下文 / 失败态」收敛成纯函数。
 *
 * 拆出来不是为分层好看，而是三件事必须可穷举地被守卫断言：
 *  1. 主体上下文只有一份：GET 回显 liked/reported 与 POST 写入用同一组
 *     (_identity / _ip / visitor_id)。此前 view() 只传 post_slug，主体恒为空，
 *     列表里的 liked 字段因此永远是 false —— 一张安静的空头支票。
 *  2. 表单意图走白名单，未知值明确 422，绝不静默当成「发表评论」处理。
 *  3. 失败态由真实异常码映射，绝不 303 假装成功。
 *
 * 独立页不认识任何身份载体（令牌归页面访问守卫）；匿名主体由稳定 Cookie 提供，
 * 优先级仍由系统层 CommentActor 单一裁决（登录 > IP 指纹 > visitor_id）。
 */
final class PostInteraction
{
    public const COOKIE = 'wx_visitor';
    public const INTENTS = ['comment', 'reply', 'like', 'report'];

    private const VISITOR_MAX = 128;

    /** @return array{0: string, 1: bool} [稳定标识, 是否本次新生成] */
    public static function visitorFrom(Request $request, string $submitted = ''): array
    {
        foreach ([$submitted, self::fromCookie((string) ($request->header('Cookie') ?? ''))] as $candidate) {
            $value = trim($candidate);
            if (self::validVisitor($value)) return [$value, false];
        }
        return [self::generateVisitor(), true];
    }

    public static function generateVisitor(): string
    {
        return 'v-' . bin2hex(random_bytes(16));
    }

    /** 只接受定界符内的可读字符：超长 / 控制字符 / 引号一律拒绝，不截断后当合法主体。 */
    public static function validVisitor(string $value): bool
    {
        return $value !== ''
            && strlen($value) <= self::VISITOR_MAX
            && preg_match('/^[A-Za-z0-9._-]+$/', $value) === 1;
    }

    private static function fromCookie(string $header): string
    {
        if ($header === '') return '';
        if (preg_match('/(?:^|;\s*)' . preg_quote(self::COOKIE, '/') . '=([^;\s]+)/', $header, $m)) {
            return rawurldecode((string) $m[1]);
        }
        return '';
    }

    public static function cookieHeader(string $visitor): string
    {
        return self::COOKIE . '=' . rawurlencode($visitor)
            . '; Path=/; Max-Age=31536000; HttpOnly; SameSite=Lax';
    }

    /**
     * @return array<string, mixed> 主体上下文；GET 与 POST 必须共用这一份
     *
     * _identity 恒为 null 是刻意的，不是漏注入：页面访问守卫的令牌解析对非 GET
     * 请求明确不读 Cookie（写操作必须自带请求头令牌，否则 CSRF 面就被打开）。
     * 浏览器原生表单无法附加 Authorization 头，所以文章页的点赞 / 举报只能落在
     * 「匿名主体」上（IP 不可逆指纹 > visitor_id）。要按登录身份互动就调 JSON 接口，
     * 那条路径由 RequestGuard 注入 _identity。
     *
     * 若有人把 _identity 在这里「补上」，等于让表单凭 Cookie 冒充登录身份 ——
     * 39 守卫会直接把这条断言打红。
     */
    public static function subject(Request $request, string $visitor): array
    {
        return [
            '_identity'  => null,
            '_ip'        => (string) ($request->clientIp() ?? ''),
            'visitor_id' => $visitor,
        ];
    }

    /** 表单没带 _intent 时按 parent_id 兼容既有回复表单；显式非法值一律拒绝。 */
    public static function intent(array $body): string
    {
        $intent = (string) ($body['_intent'] ?? '');
        if ($intent === '') return (int) ($body['parent_id'] ?? 0) > 0 ? 'reply' : 'comment';
        if (!in_array($intent, self::INTENTS, true)) {
            throw new InvalidArgumentException('未知的互动意图：' . $intent);
        }
        return $intent;
    }

    /** @return array{operation: string, input: array<string, mixed>}|null null 表示评论意图由页面既有路径处理 */
    public static function interaction(string $intent, array $body): ?array
    {
        if ($intent === 'comment' || $intent === 'reply') return null;

        $id = (int) ($body['comment_id'] ?? 0);
        if ($id <= 0) throw new InvalidArgumentException('comment_id 必填且必须为正整数');

        if ($intent === 'like') {
            $vote = (string) ($body['vote'] ?? 'add');
            if (!in_array($vote, ['add', 'remove'], true)) {
                throw new InvalidArgumentException('vote 只能是 add / remove');
            }
            return ['operation' => 'comments.like', 'input' => ['id' => $id, 'action' => $vote]];
        }

        $reason = (string) ($body['reason'] ?? '');
        if ($reason === '') $reason = 'other';
        if (!CommentInteractionPolicy::reasonValid($reason)) {
            throw new InvalidArgumentException('reason 只能是 spam / abuse / offtopic / other');
        }
        return ['operation' => 'comments.report', 'input' => ['id' => $id, 'reason' => $reason]];
    }

    /** 递归补 can_interact：主题只消费布尔，不持有「哪种状态可点赞」的业务规则。 */
    public static function decorateTree(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) continue;
            $node['can_interact'] = CommentInteractionPolicy::interactable($node);
            if (($node['children'] ?? []) !== []) {
                $node['children'] = self::decorateTree((array) $node['children']);
            }
            $out[] = $node;
        }
        return $out;
    }

    /** @return array{status: int, code: int, message: string} 真实异常码 → 页面失败态 */
    public static function failure(\Throwable $e): array
    {
        if ($e instanceof ApiOperationException) {
            $code = $e->apiCode;
            $status = match (true) {
                $code >= 50000 => 503,
                $code >= 42900 && $code < 43000 => 429,
                $code >= 40900 && $code < 41000 => 409,
                $code >= 40400 && $code < 40500 => 404,
                $code >= 40300 && $code < 40400 => 403,
                default => 422,
            };
            return ['status' => $status, 'code' => $code, 'message' => $e->getMessage()];
        }
        return ['status' => 422, 'code' => 42201, 'message' => $e->getMessage()];
    }
}
