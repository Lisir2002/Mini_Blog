<?php
declare(strict_types=1);
namespace Wx\Systems\Api;

/**
 * 错误文案目录 —— 稳定键 + 多语言包，与 Envelope::TABLE 解耦。
 *
 * 为什么不改 TABLE：它是「统一错误码表」的唯一事实源，被 CodeTable 对账器、
 * 28 组守卫、接口文档生成器、OpenAPI 导出器四处读取，中文说明还与 SPEC.md §3
 * 逐条对应。中文默认档因此直接回落到 TABLE（零复制、零二次漂移）；本目录只
 * 外置其它语言，让客户端有稳定键可分支，而不是拿中文文案去匹配。
 *
 * 默认 zh-CN 恒等：message 逐字取自 TABLE，成功信封完全不改写 —— 语义等价
 * 与既有守卫都以此为前提。只有显式协商出非默认语言时才替换文案。
 */
final class MessageCatalog
{
    public const DEFAULT_LOCALE = 'zh-CN';
    private const SUPPORTED = ['zh-CN', 'en-US'];

    /** @var array<int, string> en-US 文案；键必须覆盖 TABLE 全部码，缺项回落中文。 */
    private const EN = [
        40401 => 'Endpoint not found',
        40402 => 'Lifecycle unit not found',
        40403 => 'Resource not found',
        40404 => 'Category or tag not found',
        40501 => 'HTTP method not allowed',
        42201 => 'Request parameters failed validation',
        40901 => 'State conflict',
        40101 => 'Authentication required',
        40102 => 'Invalid or expired token',
        40103 => 'Account disabled',
        40105 => 'Invalid username or password',
        40301 => 'Permission denied',
        40302 => 'Write endpoints are not exposed publicly',
        40303 => 'Permission decision did not allow the operation (fail-closed)',
        40304 => 'Registration is closed',
        40902 => 'Idempotency key replayed with different parameters',
        40905 => 'A request with the same idempotency key is in progress',
        40903 => 'Migration is irreversible; rollback refused',
        40904 => 'Migration checksum conflict; upgrade refused',
        40906 => 'Would remove the last administrator or disable the current account',
        40907 => 'Username already taken',
        40908 => 'Post state transition is unreachable',
        40909 => 'Post is in the trash',
        40910 => 'Post is not in the trash',
        40911 => 'Category or tag already exists',
        40912 => 'Comment edit window has closed',
        40913 => 'Comment was modified by another edit',
        40914 => 'Comment state does not allow this interaction',
        42901 => 'Too many requests',
        42902 => 'Account temporarily locked',
        42903 => 'Comment submitted too frequently',
        50001 => 'Internal server error',
        50002 => 'Migration failed',
        50003 => 'Post-upgrade health check failed',
        50004 => 'Database unavailable',
        50101 => 'Endpoint registered but handler not implemented',
    ];

    /** 稳定键委托给信封（唯一真源），语言包不另立一份前缀，避免二次漂移。 */
    public static function detailKey(int $code): string { return Envelope::detailKey($code); }

    /** 默认语言逐字回落 TABLE；其它语言查包，缺项亦回落中文（不冒充翻译）。 */
    public static function message(int $code, string $locale): string
    {
        $zh = Envelope::TABLE[$code][1] ?? '未知错误';
        return $locale === 'en-US' ? (self::EN[$code] ?? $zh) : $zh;
    }

    /**
     * 协商：Accept-Language 带 q 加权，命中支持语言即用；未知 / 空一律回落站点
     * 配置默认，再回落 zh-CN。绝不因为一个畸形头就改变默认档。
     */
    public static function negotiate(?string $acceptLanguage, string $fallback): string
    {
        $base = in_array($fallback, self::SUPPORTED, true) ? $fallback : self::DEFAULT_LOCALE;
        if ($acceptLanguage === null || trim($acceptLanguage) === '') return $base;
        $best = ''; $bestQ = -1.0;
        foreach (explode(',', $acceptLanguage) as $part) {
            $bits = explode(';', trim($part));
            $tag  = strtolower(trim($bits[0]));
            $q    = 1.0;
            if (isset($bits[1]) && str_starts_with(strtolower(trim($bits[1])), 'q=')) {
                $q = (float) substr(trim($bits[1]), 2);
            }
            foreach (self::SUPPORTED as $lang) {
                $l = strtolower($lang);
                if ($tag === $l || str_starts_with($tag, $l . '-') || $tag === explode('-', $l)[0]) {
                    if ($q > $bestQ) { $best = $lang; $bestQ = $q; }
                }
            }
        }
        return $best !== '' ? $best : $base;
    }

    /**
     * 只处理错误信封：注入稳定键，非默认语言再替换文案并标注 locale。
     * 成功信封（code=0）原样返回；zh-CN 的 message 与 TABLE 逐字一致。
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function localize(array $body, string $locale): array
    {
        $code = (int) ($body['code'] ?? 0);
        if ($code === 0) return $body;
        $meta = (array) ($body['meta'] ?? []);
        $meta['detail_key'] = self::detailKey($code);
        if ($locale !== self::DEFAULT_LOCALE) {
            $body['message'] = self::message($code, $locale);
            $meta['locale']  = $locale;
        }
        $body['meta'] = $meta;
        return $body;
    }
}
