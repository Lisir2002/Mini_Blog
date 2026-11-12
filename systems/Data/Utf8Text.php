<?php
declare(strict_types=1);
namespace Wx\Systems\Data;

/**
 * UTF-8 文本原语 —— 按字符计数与截断的单一真源。
 *
 * 为什么存在：此前仓库里有 10 处「mb 探测 + 字节退化」的双路实现 ——
 * mbstring 装了就按字符、没装就按字节，**同一个输入在两个部署下会得到两种
 * 业务行为**（80 字上限的关键词，一处按 40 个汉字拒绝、另一处放行 40 个
 * 汉字共 120 字节）；更糟的是 `substr($text, 0, 200)` 这种字节兜底会把多字节
 * 字符从中间截断，产出非法 UTF-8 —— RSS 摘要末尾出现「�」，显示名被截断后
 * `json_encode` 直接返回 false，整个响应变空白。
 *
 * 单一实现（PCRE u 修饰符，PHP 内置，无扩展依赖）让行为零环境差异：
 *  - 合法 UTF-8：按字符计数 / 截断，语义与 mbstring 一致；
 *  - 非法 UTF-8（数据库脏数据等）：显式降级 —— 计数回退到字节数（宁可多拒绝
 *    也不少拒绝，长度校验是安全边界），截断回退到「字符数 × 4」的字节上界
 *    （UTF-8 每字符最多 4 字节），残留的非法序列由转义层的 ENT_SUBSTITUTE
 *    显式替换成 U+FFFD —— 乱码可见，不许假装没发生。
 */
final class Utf8Text
{
    /**
     * 字符数。非法 UTF-8 时 PCRE 失败，回退字节数 —— 计数偏大是保守方向：
     * 长度上限校验宁可误拒，不可漏放。
     */
    public static function len(string $value): int
    {
        $count = @preg_match_all('/./us', $value);
        return $count === false ? strlen($value) : $count;
    }

    /**
     * 按字符截断到 $chars 个字符。非法 UTF-8 时 PCRE 失败，回退字节上界：
     * 截出非法序列比假装截对了更诚实 —— 转义层会把它们替换成可见的 U+FFFD。
     */
    public static function clip(string $value, int $chars): string
    {
        if ($chars <= 0) return '';
        if (preg_match('/^.{0,' . $chars . '}/us', $value, $m) === 1) return $m[0];
        return substr($value, 0, $chars * 4);
    }

    /**
     * 是否合法 UTF-8。长度校验等安全边界在拒绝前先用它显式说明拒绝原因，
     * 而不是让 len() 的字节回退悄悄放行一段非法序列进库。
     */
    public static function valid(string $value): bool
    {
        return @preg_match('//us', $value) === 1;
    }
}
