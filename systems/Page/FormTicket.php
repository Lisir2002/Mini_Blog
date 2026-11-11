<?php
declare(strict_types=1);
namespace Wx\Systems\Page;

/**
 * 表单票据 —— 让「页面上的写表单」能在不读 Cookie 的前提下证明身份。
 *
 * 为什么需要它：页面守卫对非 GET 一律不读 Cookie（CSRF 边界，v0.9 起），
 * 于是 /console 这类需要 admin 的页面，表单 POST 在 route.access 阶段就是匿名，
 * 必然 401。两条捷径都不能走：为写操作打开 Cookie 会重新暴露 CSRF 面；
 * 让表单直连 JSON 接口则要求 JavaScript，且写接口还有本机来源限制。
 *
 * 票据是第三条路：GET 时由服务端签发，绑定「谁、能做什么、发往哪里、何时作废」。
 * 它不是权限凭证 —— 守卫拿到身份后仍然现场问一次 ACL，票据里的 permission
 * 只用来防止「审核票据被拿去提交内容操作」。篡改、过期、跨路径复用一律拒绝。
 *
 * 密钥是 storage/ 下自动生成的 32 字节随机串，不入库、不入仓库；
 * 不可读 / 不可写时 sign() 返回 null —— 调用方必须据此「不签发、不渲染入口」，
 * 绝不退回无签名票据那种假安全。
 */
final class FormTicket
{
    public const TTL = 1200;
    private const KEY_FILE = 'form-ticket.key';

    public function __construct(private string $storageDir) {}

    /**
     * 自助页（/account）没有权限点可绑，但仍需要「提交者是谁 + 从哪来」的证明。
     * 用 '-' 作为「无权限点」的显式标记，而不是空串：空串会让「票据字段缺失」
     * 与「本页本来就不需要权限」变成同一种状态，验签时无法区分。
     */
    public const NO_PERMISSION = '-';

    /** @return string|null 签发失败返回 null（密钥不可得），调用方不得降级 */
    public function sign(int|string $userId, string $username, string $role,
        string $permission, string $path): ?string
    {
        $key = $this->key(false);
        if ($key === null) return null;

        $payload = self::encode([
            'u' => $userId, 'n' => $username, 'r' => $role,
            'p' => $permission === '' ? self::NO_PERMISSION : $permission,
            't' => $path, 'e' => time() + self::TTL,
        ]);
        return $payload . '.' . self::mac($payload, $key);
    }

    /**
     * @return array{identity: array<string, mixed>, permission: string, path: string}|null
     *         无效 / 过期 / 越界一律 null，绝不返回「半个身份」
     */
    public function verify(string $ticket, string $path): ?array
    {
        if ($ticket === '' || substr_count($ticket, '.') !== 1) return null;
        [$payload, $mac] = explode('.', $ticket, 2);

        $key = $this->key(true);
        if ($key === null) return null;
        if (!hash_equals(self::mac($payload, $key), $mac)) return null;

        $data = self::decode($payload);
        if ($data === null) return null;
        if ((int) ($data['e'] ?? 0) < time()) return null;
        // 路径绑定：审核票据不能拿去提交文章操作，反之亦然。
        if (!hash_equals((string) ($data['t'] ?? ''), $path)) return null;
        if ((string) ($data['p'] ?? '') === '' || (string) ($data['n'] ?? '') === '') return null;
        return [
            'identity' => [
                'user_id'  => $data['u'] ?? 0,
                'username' => (string) $data['n'],
                'role'     => (string) ($data['r'] ?? 'user'),
                'scopes'   => [],
            ],
            'permission' => (string) $data['p'],
            'path'       => (string) $data['t'],
        ];
    }

    /**
     * 给页面守卫用的一步式入口：验签 + 权限绑定 + 取身份。
     *
     * 把这三件事放在票据自己身上，守卫就只负责「从请求里取字符串」，
     * 不必再认识 HMAC、路径绑定与权限比对 —— 那是安全域的内部细节。
     * 任一环节失败都返回 null，绝不返回半个身份。
     *
     * @return array<string, mixed>|null
     */
    public function identityFor(string $ticket, string $permission, string $path): ?array
    {
        // 空权限点不是「跳过校验」，而是「本页没有权限点可绑」：期望值变成 '-'，
        // 与普通票据走同一条 hash_equals 比对，绝不因为规则宽松就直接放行。
        $expected = $permission === '' ? self::NO_PERMISSION : $permission;
        $hit = $this->verify($ticket, $path);
        if ($hit === null) return null;
        if (!hash_equals($expected, $hit['permission'])) return null;
        return $hit['identity'];
    }

    /** @param array<string, mixed> $data */
    private static function encode(array $data): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');
    }

    /** @return array<string, mixed>|null */
    private static function decode(string $payload): ?array
    {
        $raw = base64_decode(strtr($payload, '-_', '+/'), true);
        if ($raw === false) return null;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private static function mac(string $payload, string $key): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $key, true)), '+/', '-_'), '=');
    }

    /** @param bool $mustExist 验签时密钥不存在即失败；签发时允许新建 */
    private function key(bool $mustExist): ?string
    {
        $path = rtrim($this->storageDir, '/') . '/' . self::KEY_FILE;
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            return is_string($raw) && strlen($raw) >= 32 ? $raw : null;
        }
        if ($mustExist) return null;
        if (!is_dir($this->storageDir) && !@mkdir($this->storageDir, 0775, true) && !is_dir($this->storageDir)) {
            return null;
        }
        $key = bin2hex(random_bytes(32));
        if (@file_put_contents($path, $key, LOCK_EX) === false) return null;
        @chmod($path, 0600);
        return $key;
    }
}
