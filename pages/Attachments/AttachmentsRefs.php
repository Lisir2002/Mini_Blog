<?php
declare(strict_types=1);
namespace Wx\Pages\Attachments;

/**
 * 正文引用检查 —— 删除 / 改名之前必须回答「文章里还在用它吗」。
 *
 * 这不是精确解析：正文可能是 Markdown、HTML 或纯文本，系统里没有结构化的
 * 「引用表」。所以只做两种可解释的字面匹配：
 *   - by_url：正文含 `/attach/download?id=N`（附件 id 是下载的唯一定址方式）；
 *   - by_name：正文含附件的展示名，且名字足够长不至于把常见词误判成引用。
 *
 * 启发式只用来「拦住明显会坏的操作」，不假装能证明「没有引用」。
 * 因此读不到正文时调用方必须 fail-closed，而不是把「查不到」当成「没引用」。
 */
final class AttachmentsRefs
{
    private const MIN_NAME = 4;

    /**
     * @param list<array<string, mixed>> $items
     * @return array<int, array{by_url: bool, by_name: bool, label: string}>
     */
    public static function scan(string $body, array $items): array
    {
        $out = [];
        foreach ($items as $row) {
            if (!is_array($row)) continue;
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) continue;
            $url = '/attach/download?id=' . $id;
            $byUrl = str_contains($body, $url);
            $name = (string) ($row['name'] ?? '');
            $byName = self::length($name) >= self::MIN_NAME && str_contains($body, $name);
            $out[$id] = ['by_url' => $byUrl, 'by_name' => $byName, 'label' => self::label($byUrl, $byName)];
        }
        return $out;
    }

    /** @return array<int, array{by_url: bool, by_name: bool, label: string}> */
    public static function unknown(array $items): array
    {
        $out = [];
        foreach ($items as $row) {
            if (!is_array($row)) continue;
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) continue;
            $out[$id] = ['by_url' => false, 'by_name' => false, 'label' => '正文读不到，无法判断'];
        }
        return $out;
    }

    /** 字符数口径单一真源见 Utf8Text（与 AttachmentsInput 一致）。 */
    private static function length(string $value): int
    {
        return \Wx\Systems\Data\Utf8Text::len($value);
    }

    private static function label(bool $byUrl, bool $byName): string
    {
        if ($byUrl && $byName) return '正文引用：URL + 展示名';
        if ($byUrl) return '正文引用：URL';
        if ($byName) return '正文引用：展示名';
        return '正文未引用';
    }
}
