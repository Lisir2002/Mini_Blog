<?php
declare(strict_types=1);
namespace Wx\Systems\Comment;

use InvalidArgumentException;

/**
 * 评论内容守卫 —— 只回答「这段正文能不能落库」，不回答「谁有资格发」。
 *
 * 规范化在入库前完成，而不是渲染时补救：存进去的就是最终形态，
 * 否则同一条评论在不同渲染路径上会得到不同结果，审核与检索也会各算一套。
 */
final class CommentGuard
{
    public function __construct(
        private int $maxLength = 5000,
        private int $maxLinks = 3
    ) {}

    /**
     * @return string 规范化后的正文
     * @throws InvalidArgumentException 空内容 / 超长 / 链接过多 / 不可见字符
     */
    public function inspect(string $body): string
    {
        // 统一换行并剔除除 \n \t 外的 C0 控制字符：零宽字符与退格会让同一条评论「看起来一样、字节不同」，
        // 既能绕过重复检测，也能在页面上伪造排版。
        $text = str_replace(["\r\n", "\r"], "\n", $body);
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        $text = trim($text);

        if ($text === '') {
            throw new InvalidArgumentException('评论内容不能为空');
        }
        $length = self::length($text);
        if ($length > $this->maxLength) {
            throw new InvalidArgumentException("评论超出 {$this->maxLength} 字上限（当前 {$length} 字）");
        }
        $links = preg_match_all('#https?://#i', $text);
        if ($links !== false && $links > $this->maxLinks) {
            throw new InvalidArgumentException("单条评论最多 {$this->maxLinks} 个链接（当前 {$links} 个）");
        }
        return $text;
    }

    /** 字符数，不依赖 mbstring；非法 UTF-8 退化为字节数，宁可多算也不放过超长内容。 */
    public static function length(string $text): int
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        return $chars === false ? strlen($text) : count($chars);
    }
}
