<?php
declare(strict_types=1);
namespace Wx\Systems\Emoji;

/**
 * 表情解析器 —— 把一段正文切成 token 序列，而不是拼一段 HTML。
 *
 * 这个选择是安全边界的核心：系统层永远不产出标签，主题才决定怎么渲染。
 * 未知短码保持原文字面量（用户看到的是 :not_a_real_code:，而不是被吞掉），
 * 因此新增 / 删除表情永远不会改写历史内容。
 */
final class EmojiParser
{
    /** 短码两侧必须是边界，避免把 URL 里的 :8080: 或代码片段误判成表情。 */
    private const PATTERN = '/(?<![\w:]):([a-z0-9_+-]{2,32}):(?![\w:])/i';

    public function __construct(private EmojiCatalogInterface $catalog) {}

    /** @return list<array{type: string, value?: string, code?: string, kind?: string, alt?: string}> */
    public function parse(string $text): array
    {
        if ($text === '' || !str_contains($text, ':')) {
            return [['type' => 'text', 'value' => $text]];
        }
        $tokens = [];
        $offset = 0;
        $matched = preg_match_all(self::PATTERN, $text, $all, PREG_OFFSET_CAPTURE);
        if ($matched === false || $matched === 0) {
            return [['type' => 'text', 'value' => $text]];
        }
        foreach ($all[0] as $index => [$literal, $position]) {
            $code = strtolower((string) $all[1][$index][0]);
            $emoji = $this->catalog->find($code);
            if ($emoji === null) continue;
            if ($position > $offset) {
                $tokens[] = ['type' => 'text', 'value' => substr($text, $offset, $position - $offset)];
            }
            $tokens[] = [
                'type'  => 'emoji',
                'code'  => $code,
                'kind'  => (string) $emoji['kind'],
                'value' => (string) $emoji['value'],
                'alt'   => ':' . $code . ':',
            ];
            $offset = $position + strlen($literal);
        }
        if ($offset < strlen($text)) {
            $tokens[] = ['type' => 'text', 'value' => substr($text, $offset)];
        }
        return $tokens === [] ? [['type' => 'text', 'value' => $text]] : $tokens;
    }

    /** 纯文本降级：没有主题、没有图片时也能得到可读内容（图片表情退化为 :code:）。 */
    public function plain(string $text): string
    {
        $out = '';
        foreach ($this->parse($text) as $token) {
            $out .= $token['type'] === 'text' ? (string) $token['value'] : (string) ($token['alt'] ?? '');
        }
        return $out;
    }
}
