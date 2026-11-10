<?php
declare(strict_types=1);
namespace Wx\Systems\User;

/**
 * 口令与用户名规则 —— 注册、改密、管理员重置共用同一套判断。
 *
 * 放在独立协作者里的理由：三条写入路径如果各写一遍校验，迟早会出现
 * 「注册要求 8 位，管理员重置允许 4 位」这种同一条安全线在两个入口不等高的情况。
 *
 * 这里有一条容易被忽略的硬约束：bcrypt 只取前 72 字节作为有效输入。
 * 超过 72 字节的口令，`password_hash()` 会静默截断 —— 用户以为设了长口令，
 * 实际后半段随便输都能登录。这不是理论问题，而是真实的「看起来更安全、实际更弱」，
 * 所以必须在入口显式拒绝，而不是交给哈希函数悄悄处理。
 */
final class CredentialPolicy
{
    public const MIN_LENGTH = 8;
    public const MAX_BYTES  = 72;

    /**
     * 最小长度可由配置覆盖，但下限锁死在 8：把阈值调低到 4 位不是「灵活」，
     * 而是把一个安全决策交给了一个可能被随手改掉的配置项。
     */
    public function __construct(private int $minLength = self::MIN_LENGTH)
    {
        $this->minLength = max(self::MIN_LENGTH, $minLength);
    }

    /** 常见弱口令：注册与改密一律拒绝，管理员重置也走同一张表。 */
    private const WEAK = [
        'password', 'password1', 'password123', '12345678', '123456789', '1234567890',
        'qwertyui', 'qwerty123', 'abc12345', 'admin123', 'admin1234', 'letmein1',
        'iloveyou', '11111111', '00000000', '88888888', 'woaini1314', 'a1234567',
    ];

    /** 用户名规则：字母数字与下划线，3~32 位，不区分大小写判重。 */
    public function usernameError(string $username): ?string
    {
        if ($username === '') return '用户名不能为空';
        if (preg_match('/^[A-Za-z0-9_]{3,32}$/', $username) !== 1) {
            return '用户名只能是 3~32 位的字母、数字或下划线';
        }
        // 纯数字用户名会与「按 ID 查找」的标识解释冲突（user.lookup 把数字当 ID）。
        if (ctype_digit($username)) return '用户名不能是纯数字';
        return null;
    }

    public function passwordError(string $username, string $password): ?string
    {
        $length = strlen($password);   // 按字节判断，与 bcrypt 的截断口径一致
        if ($length < $this->minLength) return '口令至少 ' . $this->minLength . ' 位';
        if ($length > self::MAX_BYTES) return '口令不能超过 ' . self::MAX_BYTES . ' 字节';
        if (strcasecmp($password, $username) === 0) return '口令不能与用户名相同';
        if (in_array(strtolower($password), self::WEAK, true)) return '该口令过于常见，请更换';
        // 单一字符重复（aaaaaaaa）与连续键盘序列（12345678）同样属于弱口令。
        if (preg_match('/^(.)\1+$/u', $password) === 1) return '口令不能是重复的单一字符';
        if (preg_match('/^(?:0123456789|1234567890|abcdefgh|qwertyui|asdfghjk)/i', $password) === 1) {
            return '口令不能是连续序列';
        }
        return null;
    }

    /** 口令强度只需要「够不够」，不需要「多强」；返回 0~3 仅用于提示文案。 */
    public function strength(string $password): int
    {
        $score = 0;
        if (strlen($password) >= 12) $score++;
        if (preg_match('/[a-z]/', $password) === 1 && preg_match('/[A-Z]/', $password) === 1) $score++;
        if (preg_match('/\d/', $password) === 1 && preg_match('/[^A-Za-z0-9]/', $password) === 1) $score++;
        return $score;
    }
}
