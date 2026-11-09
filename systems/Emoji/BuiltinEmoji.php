<?php
declare(strict_types=1);
namespace Wx\Systems\Emoji;

/**
 * 内置表情表 —— 短码 → 字形。数据即代码，不落库、不联网、不依赖 CDN。
 *
 * 三种来源刻意放在同一张表里：Unicode emoji、颜文字（多字符组合）、以及后续的自定义图片。
 * 对解析器它们只有一种身份：一个稳定短码对应一个可渲染值。
 */
final class BuiltinEmoji
{
    /** @var array<string, array{kind: string, value: string}> */
    private const MAP = [
        'smile' => ['kind' => 'unicode', 'value' => '😄'],
        'joy' => ['kind' => 'unicode', 'value' => '😂'],
        'laugh' => ['kind' => 'unicode', 'value' => '😆'],
        'wink' => ['kind' => 'unicode', 'value' => '😉'],
        'blush' => ['kind' => 'unicode', 'value' => '😊'],
        'heart' => ['kind' => 'unicode', 'value' => '❤️'],
        'heart_eyes' => ['kind' => 'unicode', 'value' => '😍'],
        'thinking' => ['kind' => 'unicode', 'value' => '🤔'],
        'eyes' => ['kind' => 'unicode', 'value' => '👀'],
        'wow' => ['kind' => 'unicode', 'value' => '😮'],
        'cry' => ['kind' => 'unicode', 'value' => '😢'],
        'sad' => ['kind' => 'unicode', 'value' => '🙁'],
        'angry' => ['kind' => 'unicode', 'value' => '😠'],
        'sweat' => ['kind' => 'unicode', 'value' => '😅'],
        'cool' => ['kind' => 'unicode', 'value' => '😎'],
        'sleep' => ['kind' => 'unicode', 'value' => '😴'],
        'thumbsup' => ['kind' => 'unicode', 'value' => '👍'],
        'thumbsdown' => ['kind' => 'unicode', 'value' => '👎'],
        'clap' => ['kind' => 'unicode', 'value' => '👏'],
        'wave' => ['kind' => 'unicode', 'value' => '👋'],
        'ok' => ['kind' => 'unicode', 'value' => '👌'],
        'pray' => ['kind' => 'unicode', 'value' => '🙏'],
        'muscle' => ['kind' => 'unicode', 'value' => '💪'],
        'tada' => ['kind' => 'unicode', 'value' => '🎉'],
        'party' => ['kind' => 'unicode', 'value' => '🥳'],
        'fire' => ['kind' => 'unicode', 'value' => '🔥'],
        'rocket' => ['kind' => 'unicode', 'value' => '🚀'],
        'star' => ['kind' => 'unicode', 'value' => '⭐'],
        'sparkles' => ['kind' => 'unicode', 'value' => '✨'],
        'bulb' => ['kind' => 'unicode', 'value' => '💡'],
        'bug' => ['kind' => 'unicode', 'value' => '🐛'],
        'coffee' => ['kind' => 'unicode', 'value' => '☕'],
        'beer' => ['kind' => 'unicode', 'value' => '🍺'],
        'cake' => ['kind' => 'unicode', 'value' => '🍰'],
        'gift' => ['kind' => 'unicode', 'value' => '🎁'],
        'check' => ['kind' => 'unicode', 'value' => '✅'],
        'cross' => ['kind' => 'unicode', 'value' => '❌'],
        'warning' => ['kind' => 'unicode', 'value' => '⚠️'],
        'lock' => ['kind' => 'unicode', 'value' => '🔒'],
        'key' => ['kind' => 'unicode', 'value' => '🔑'],
        'bell' => ['kind' => 'unicode', 'value' => '🔔'],
        'clock' => ['kind' => 'unicode', 'value' => '🕒'],
        'book' => ['kind' => 'unicode', 'value' => '📖'],
        'code' => ['kind' => 'unicode', 'value' => '🧑‍💻'],
        'php' => ['kind' => 'unicode', 'value' => '🐘'],
        // 颜文字：同样走短码，避免用户手动输入时被不同字体拆成错位的半角符号
        'shrug' => ['kind' => 'kaomoji', 'value' => '¯\_(ツ)_/¯'],
        'tableflip' => ['kind' => 'kaomoji', 'value' => '(╯°□°)╯︵ ┻━┻'],
        'unflip' => ['kind' => 'kaomoji', 'value' => '┬─┬ノ( º _ ºノ)'],
        'lenny' => ['kind' => 'kaomoji', 'value' => '( ͡° ͜ʖ ͡°)'],
        'bear' => ['kind' => 'kaomoji', 'value' => 'ʕ•ᴥ•ʔ'],
        'happy' => ['kind' => 'kaomoji', 'value' => '(◕‿◕)'],
        'cry2' => ['kind' => 'kaomoji', 'value' => '(╥﹏╥)'],
    ];

    /** @return array<string, array{kind: string, value: string}> */
    public static function all(): array
    {
        return self::MAP;
    }

    /** @return array{kind: string, value: string}|null */
    public static function find(string $code): ?array
    {
        return self::MAP[strtolower($code)] ?? null;
    }

    public static function has(string $code): bool
    {
        return isset(self::MAP[strtolower($code)]);
    }

    /** 短码语法：2–32 位小写字母、数字、下划线、加号、短横线。与 :smile: 的书写习惯一致。 */
    public static function validCode(string $code): bool
    {
        return preg_match('/^[a-z0-9_+-]{2,32}$/', $code) === 1;
    }
}
