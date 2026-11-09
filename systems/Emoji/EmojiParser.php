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
        return $this->tokenize($text, $this->catalog->findMany($this->codes($text)));
    }

    /**
     * 批量解析：整页文章 / 整串评论只查一次目录。
     *
     * 这是列表页唯一的正确用法。逐条 parse() 的代价不是「多几条 SQL」，而是
     * 「页面条数 × 短码种类」——条数一涨，延迟就线性劣化，且无法用固定预算守住。
     * @param list<string> $texts
     * @return list<list<array<string, mixed>>>
     */
    public function parseMany(array $texts): array
    {
        $all = [];
        foreach ($texts as $text) {
            foreach ($this->codes((string) $text) as $code) $all[] = $code;
        }
        $found = $this->catalog->findMany($all);
        $out = [];
        foreach ($texts as $text) $out[] = $this->tokenize((string) $text, $found);
        return $out;
    }

    /** @return list<string> 文本里出现过的短码（小写，含重复，交由目录去重） */
    private function codes(string $text): array
    {
        if ($text === '' || !str_contains($text, ':')) return [];
        if (preg_match_all(self::PATTERN, $text, $all) === false) return [];
        $codes = [];
        foreach ($all[1] as $captured) $codes[] = strtolower((string) $captured);
        return $codes;
    }

    /**
     * @param array<string, array<string, mixed>> $found
     * @return list<array<string, mixed>>
     */
    private function tokenize(string $text, array $found): array
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
            $emoji = $found[$code] ?? null;
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
