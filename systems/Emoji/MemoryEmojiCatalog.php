<?php
declare(strict_types=1);
namespace Wx\Systems\Emoji;

/** 表情目录的内存实现：行为与 PDO 版一致，供无数据库启动与测试使用。 */
final class MemoryEmojiCatalog implements EmojiCatalogInterface
{
    /** @var array<string, array<string, mixed>> */
    private static array $rows = [];

    public function find(string $code): ?array
    {
        $code = strtolower($code);
        $builtin = BuiltinEmoji::find($code);
        if ($builtin !== null) {
            return ['code' => $code, 'kind' => $builtin['kind'], 'value' => $builtin['value'],
                'attachment_id' => 0, 'custom' => false];
        }
        return self::$rows[$code] ?? null;
    }

    public function all(): array
    {
        $out = [];
        foreach (BuiltinEmoji::all() as $code => $emoji) {
            $out[$code] = ['code' => $code, 'kind' => $emoji['kind'], 'value' => $emoji['value'],
                'attachment_id' => 0, 'custom' => false];
        }
        foreach (self::$rows as $code => $row) $out[$code] = $row;
        ksort($out);
        return $out;
    }

    public function custom(): array
    {
        $rows = array_values(self::$rows);
        usort($rows, static fn(array $a, array $b): int => strcmp((string) $a['code'], (string) $b['code']));
        return $rows;
    }

    public function register(array $emoji): bool
    {
        $code = strtolower((string) ($emoji['code'] ?? ''));
        if ($code === '' || BuiltinEmoji::has($code) || isset(self::$rows[$code])) return false;
        $id = (int) ($emoji['attachment_id'] ?? 0);
        self::$rows[$code] = [
            'code' => $code,
            'kind' => (string) ($emoji['kind'] ?? 'image'),
            'value' => $id > 0 ? '/attach/download?id=' . $id : (string) ($emoji['value'] ?? ''),
            'attachment_id' => $id,
            'custom' => true,
        ];
        return true;
    }

    public function remove(string $code): bool
    {
        $code = strtolower($code);
        if (BuiltinEmoji::has($code) || !isset(self::$rows[$code])) return false;
        unset(self::$rows[$code]);
        return true;
    }

    /** 仅测试用。 */
    public static function reset(): void { self::$rows = []; }
}
