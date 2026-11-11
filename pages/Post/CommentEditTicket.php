<?php
declare(strict_types=1);
namespace Wx\Pages\Post;

/**
 * 编辑票据 —— 让「文章页的编辑表单」能证明「提交者就是这条评论的作者」。
 *
 * 为什么需要它：页面访问守卫刻意只对 GET 读 Cookie（写操作必须带请求头令牌），
 * 浏览器原生表单无法附加 Authorization。若页面表单直接调用 comments.edit，
 * 身份恒为空 —— 编辑入口会变成第二张永远兑现不了的支票。
 *
 * 票据不是第二套登录：它由服务端在**已认证的 GET** 上签发，绑定
 * (评论 id, 作者 id, 当前 edit_count, 过期时间)，用站点本地密钥做 HMAC。
 * 跨站拿不到页面里的票据（同源策略），所以 CSRF 面不扩大；
 * 提交一次 edit_count 就变化，旧票据自然失效 —— 不需要一次性记录表。
 *
 * 密钥是 storage 下自动生成的 32 字节随机串：不入库、不进仓库。不可读 / 不可写时
 * 直接不签发（入口不出现），绝不退回「无签名票据」那种假安全。
 */
final class CommentEditTicket
{
    private const FILE = '/storage/comment-ticket.key';
    private const TTL  = 900;   // 15 分钟；页面停留更久就刷新重签

    private function __construct(private string $key) {}

    /** 惰性读取密钥：文件不存在则原子生成一次，失败返回 null（调用方据此不渲染入口）。 */
    public static function fromKernel(string $root): ?self
    {
        $file = $root . self::FILE;
        if (!is_file($file)) {
            $key = bin2hex(random_bytes(32));
            $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, $key) === false) {
                error_log('wx: comment edit ticket disabled: storage not writable');
                return null;
            }
            @chmod($tmp, 0600);
            if (!@rename($tmp, $file)) {
                @unlink($tmp);
                error_log('wx: comment edit ticket disabled: rename failed');
                return null;
            }
        }
        $key = trim((string) @file_get_contents($file));
        if (strlen($key) < 32) {
            error_log('wx: comment edit ticket disabled: key too short');
            return null;
        }
        return new self($key);
    }

    /** @return string 空串表示无法签发，主题据此不显示编辑表单 */
    public function issue(int $commentId, int $userId, int $editCount, int $now): string
    {
        if ($commentId <= 0 || $userId <= 0 || $editCount < 0) return '';
        $payload = self::encode([
            'cid' => $commentId, 'uid' => $userId, 'cnt' => $editCount, 'exp' => $now + self::TTL,
        ]);
        return $payload . '.' . self::sign($payload, $this->key);
    }

    /**
     * 只验「签名 + 过期」。作者与版本是否仍匹配由 comments.edit 自己再判一次：
     * 票据说「15 分钟前 uid=3 拿到过 cnt=0」，这不能替代当下的权限与乐观锁。
     *
     * @return array{cid: int, uid: int, cnt: int, exp: int}|null
     */
    public function parse(string $ticket, int $now): ?array
    {
        $parts = explode('.', $ticket);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') return null;
        if (!hash_equals(self::sign($parts[0], $this->key), $parts[1])) return null;
        $b64 = strtr($parts[0], '-_', '+/');
        $raw = base64_decode($b64 . str_repeat('=', (4 - strlen($b64) % 4) % 4), true);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) return null;
        $out = [
            'cid' => (int) ($data['cid'] ?? 0), 'uid' => (int) ($data['uid'] ?? 0),
            'cnt' => (int) ($data['cnt'] ?? -1), 'exp' => (int) ($data['exp'] ?? 0),
        ];
        if ($out['cid'] <= 0 || $out['uid'] <= 0 || $out['cnt'] < 0) return null;
        if ($out['exp'] < $now) return null;
        return $out;
    }

    /** @param array<string, int> $data */
    private static function encode(array $data): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');
    }

    private static function sign(string $payload, string $key): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $key, true)), '+/', '-_'), '=');
    }

    /**
     * 给评论树里「本人且当前可编辑」的节点附票据。只加字段，不改判定：
     * editable / own 都由评论系统按同一策略算出，这里不重新解释规则。
     * 匿名（uid=0）一律不加 —— 编辑本来就是登录作者的能力。
     *
     * @param array<int, array<string, mixed>> $nodes
     * @return array<int, array<string, mixed>>
     */
    public function decorate(array $nodes, ?array $identity, int $now): array
    {
        $uid = (int) ($identity['user_id'] ?? 0);
        $out = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) continue;
            if ($uid > 0 && !empty($node['editable']) && !empty($node['own'])) {
                $ticket = $this->issue((int) ($node['id'] ?? 0), $uid,
                    (int) ($node['edit_count'] ?? 0), $now);
                if ($ticket !== '') {
                    $node['edit_ticket'] = $ticket;
                    $node['edit_action'] = '/post';
                }
            }
            if (is_array($node['children'] ?? null) && $node['children'] !== []) {
                $node['children'] = $this->decorate((array) $node['children'], $identity, $now);
            }
            $out[] = $node;
        }
        return $out;
    }
}
