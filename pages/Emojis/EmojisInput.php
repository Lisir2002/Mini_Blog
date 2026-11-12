<?php
declare(strict_types=1);
namespace Wx\Pages\Emojis;

/**
 * 表情写输入校验 —— 挡住接口不报错但会写脏数据、或报错却说不清原因的场景。
 *
 * 三类预查都在页面层完成：短码语法、内置 / 已登记占用（40901 不复用）、删除确认。
 * 附件存在性与 image/* 仍由接口裁决：没有「按 id 查单个附件」的只读接口，
 * 页面无法低成本核对，只能把 40403 按动作分别翻译（登记 = 附件不存在，删除 = 表情不存在）。
 */
final class EmojisInput
{
    public const OPS = ['register', 'delete'];

    /** @param array<string, mixed> $body @param list<string> $codes @param list<string> $customCodes
     *  @return array<string, mixed> */
    public static function check(array $body, array $codes, array $customCodes): array
    {
        $op = strtolower(trim((string) ($body['op'] ?? '')));
        if (!in_array($op, self::OPS, true)) {
            return self::no('未知操作「' . ($op === '' ? '(空)' : $op) . '」：只能是 register / delete。');
        }
        return $op === 'register'
            ? self::register($body, $codes)
            : self::delete($body, $codes, $customCodes);
    }

    /** @param array<string, mixed> $body @param list<string> $codes
     *  @return array<string, mixed> */
    private static function register(array $body, array $codes): array
    {
        $code = strtolower(trim((string) ($body['code'] ?? '')));
        if (preg_match('/^[a-z0-9_+-]{2,32}$/', $code) !== 1) {
            return self::no('短码只能是 2–32 位小写字母、数字、下划线、加号或短横线，当前为「'
                . ($code === '' ? '(空)' : $code) . '」。');
        }
        if (in_array($code, $codes, true)) {
            return self::no('短码 :' . $code . ': 已被内置表情或已登记表情占用（40901 不复用）；'
                . '换一个短码，或先删除已有的自定义表情。');
        }
        $aid = (int) ($body['attachment_id'] ?? 0);
        if ($aid <= 0 || (string) $aid !== trim((string) ($body['attachment_id'] ?? ''))) {
            return self::no('attachment_id 必须是正整数，且只能是本站已上传的图片附件 id：'
                . '外链表情会让每次浏览都向第三方发请求，也能被随时替换。');
        }
        return self::yes('register', ['code' => $code, 'attachment_id' => $aid],
            '登记 :' . $code . ': → 附件 #' . $aid . '。',
            '接口还会复核附件存在且 mime 为 image/*；不存在会回报「引用的附件不存在」。');
    }

    /** @param array<string, mixed> $body @param list<string> $codes @param list<string> $customCodes
     *  @return array<string, mixed> */
    private static function delete(array $body, array $codes, array $customCodes): array
    {
        $code = strtolower(trim((string) ($body['code'] ?? '')));
        if ($code === '') return self::no('删除必须指定短码。');
        if (!in_array($code, $customCodes, true)) {
            if (in_array($code, $codes, true)) {
                return self::no(':' . $code . ': 是内置表情，不可删除（40901）；目录里也不给它删除按钮。');
            }
            return self::no('自定义表情 :' . $code . ': 不存在（40403）；请从下面的目录里选一个。');
        }
        $confirm = strtolower(trim((string) ($body['confirm'] ?? '')));
        if ($confirm !== $code) {
            return self::no('删除请手写短码 ' . $code . ' 确认（当前填的是「'
                . ($confirm === '' ? '(空)' : $confirm) . '」）。');
        }
        return self::yes('delete', ['code' => $code],
            '删除自定义表情 :' . $code . ':。',
            '正文存的是 :' . $code . ': 原文、渲染时才解析，所以删除不会重写历史：'
                . '读者仍看到字面量，只是不再渲染成图片。');
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private static function yes(string $op, array $params, string $detail, string $hint): array
    {
        return ['ok' => true, 'op' => $op, 'params' => $params,
            'detail' => $detail, 'hint' => $hint, 'message' => ''];
    }

    /** @return array<string, mixed> */
    private static function no(string $message): array
    {
        return ['ok' => false, 'op' => '', 'params' => [],
            'detail' => '', 'hint' => '', 'message' => $message];
    }
}
