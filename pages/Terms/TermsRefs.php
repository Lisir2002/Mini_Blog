<?php
declare(strict_types=1);
namespace Wx\Pages\Terms;

/**
 * 术语引用的解析工具 —— 纯函数，无 IO。
 *
 * `content.attach` 只认 `kind:slug` 且只接受已存在的术语：拼错的引用会返回
 * 40404，而不是悄悄多出一个只有一篇文章的词条。页面在提交前先按清单核对一遍，
 * 好处不是「替接口省一次查询」，而是能说清到底哪一个引用拼错了。
 */
final class TermsRefs
{
    /** 按字符计数，与名称上限的显示口径一致：8 个汉字不该被算成 24 个字。 */
    public static function length(string $value): int
    {
        if (function_exists('mb_strlen')) return mb_strlen($value, 'UTF-8');
        return count(preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /** @return list<string> 逗号 / 换行 / 空白分隔，去重后保持输入顺序 */
    public static function split(string $text): array
    {
        $parts = preg_split('/[\s,，、]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $ref = trim((string) $part);
            if ($ref !== '' && !in_array($ref, $out, true)) $out[] = $ref;
        }
        return $out;
    }

    /**
     * @param list<string> $refs
     * @param array<string, array<string, mixed>> $known
     * @return array{ok: true, refs: list<string>}|array{ok: false, message: string}
     */
    public static function check(array $refs, array $known): array
    {
        foreach ($refs as $ref) {
            if (!preg_match('/^(category|tag):[a-z0-9][a-z0-9-]{0,190}$/', $ref)) {
                return ['ok' => false, 'message' => '引用「' . $ref . '」写法不对：应为 category:slug 或 tag:slug。'
                    . '接口会直接报 42201，页面先说清是哪一个。'];
            }
            if (!isset($known[$ref])) {
                return ['ok' => false, 'message' => '术语「' . $ref . '」不在当前清单里。'
                    . 'content.attach 只接受已存在的术语，不会替你新建；拼错会返回 40404。'];
            }
        }
        return ['ok' => true, 'refs' => $refs];
    }

    /** @param array<string, array<string, mixed>> $known */
    public static function byId(array $known, int $id): ?array
    {
        foreach ($known as $term) {
            if ((int) ($term['id'] ?? 0) === $id) return $term;
        }
        return null;
    }

    /** @param list<array<string, mixed>> $attached @return string 预填进挂载框的当前集合 */
    public static function prefill(array $attached): string
    {
        $lines = [];
        foreach ($attached as $term) {
            $kind = (string) ($term['kind'] ?? '');
            $slug = (string) ($term['slug'] ?? '');
            if ($kind !== '' && $slug !== '') $lines[] = $kind . ':' . $slug;
        }
        return implode("\n", $lines);
    }
}
