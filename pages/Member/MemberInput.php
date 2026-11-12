<?php
declare(strict_types=1);
namespace Wx\Pages\Member;

/**
 * 成长写操作的输入校验 —— 页面层必须先挡住「接口不报错、但结果是脏数据」的输入。
 *
 * 三条理由：
 *  1. 接口层把 upsert/delete 交给存储，写错的名字会被静默写进去
 *     （授予一个不存在的头衔 → 关系落库、档案里永远看不见，且不报错）；
 *  2. op 只能由提交按钮给出，缺失一律 422 —— 默认成 upsert 等于让「没点按钮的请求」
 *     悄悄改了配置；
 *  3. 删除定义类配置要求手写主键确认，两个按钮并排时误点就没有撤销机会。
 *
 * 纯函数，无 IO：只把表单字段翻译成接口参数，不碰容器、不碰权限、不碰执行者。
 */
final class MemberInput
{
    /** @return array{ok: true, in: array<string, mixed>, key: string, op: string}
     *          |array{ok: false, message: string} */
    public static function normalize(string $action, array $body): array
    {
        $op = trim((string) ($body['op'] ?? ''));
        if ($op === '') {
            return self::no('没有提交动作：请通过表单按钮提交，不要直接构造 POST。');
        }
        return match ($action) {
            'award'       => self::award($body, $op),
            'xp_rule'     => self::xpRule($body, $op),
            'title_def'   => self::titleDef($body, $op),
            'title_grant' => self::titleGrant($body, $op),
            'level_def'   => self::levelDef($body, $op),
            default       => self::no('未知操作「' . $action . '」。'),
        };
    }

    /** @param array<string, mixed> $body
     *  @return array{ok: true, in: array<string, mixed>, key: string, op: string}|array{ok: false, message: string} */
    private static function award(array $body, string $op): array
    {
        if ($op !== 'award') return self::no('发经验的 op 只能是 award。');
        $user  = trim((string) ($body['user_id'] ?? ''));
        $do    = trim((string) ($body['xp_action'] ?? ''));
        $event = trim((string) ($body['event_key'] ?? ''));
        $raw   = trim((string) ($body['amount'] ?? ''));
        $note  = trim((string) ($body['note'] ?? ''));
        if ($user === '' || $do === '' || $event === '') {
            return self::no('user_id / 动作 / event_key 三项都必填。');
        }
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $do) !== 1) {
            return self::no('动作只能用小写字母、数字、点、下划线、短横线（最长 64）。');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $event) !== 1) {
            return self::no('event_key 格式非法：字母或数字开头，可用 . _ : -（最长 128）。');
        }
        if (self::tooLong($note, 200)) return self::no('备注最多 200 字符。');
        $in = ['user_id' => $user, 'action' => $do, 'event_key' => $event, 'note' => $note];
        if ($raw !== '') {
            if (self::int($raw) === null || (int) $raw <= 0) {
                return self::no('amount 必须是正整数；留空表示按经验规则取值。');
            }
            $in['amount'] = (int) $raw;
        }
        return ['ok' => true, 'in' => $in, 'key' => $user, 'op' => $op];
    }

    /** @param array<string, mixed> $body
     *  @return array{ok: true, in: array<string, mixed>, key: string, op: string}|array{ok: false, message: string} */
    private static function xpRule(array $body, string $op): array
    {
        $key = trim((string) ($body['rule_action'] ?? ''));
        if (!in_array($op, ['upsert', 'delete'], true)) return self::no('经验规则的 op 只能是 upsert / delete。');
        if ($key === '') return self::no('请填写规则动作名。');
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $key) !== 1) {
            return self::no('动作只能用小写字母、数字、点、下划线、短横线（最长 64）。');
        }
        return self::definition($body, $op, $key, [
            'amount' => ['default' => 0, 'min' => 0, 'label' => '单次经验'],
            'daily_cap' => ['default' => 0, 'min' => 0, 'label' => '每日上限'],
            'enabled' => ['default' => 1, 'min' => 0, 'max' => 1, 'label' => '是否启用'],
        ], 'description', 200, ['action' => $key]);
    }

    /** @param array<string, mixed> $body
     *  @return array{ok: true, in: array<string, mixed>, key: string, op: string}|array{ok: false, message: string} */
    private static function titleDef(array $body, string $op): array
    {
        $key = trim((string) ($body['title_key'] ?? ''));
        if (!in_array($op, ['upsert', 'delete'], true)) return self::no('头衔定义的 op 只能是 upsert / delete。');
        if ($key === '') return self::no('请填写头衔标识 title_key。');
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $key) !== 1) {
            return self::no('title_key 只能用小写字母、数字、点、下划线、短横线（最长 64）。');
        }
        return self::definition($body, $op, $key, [
            'priority' => ['default' => 0, 'min' => -9999, 'label' => '优先级'],
            'visible' => ['default' => 1, 'min' => 0, 'max' => 1, 'label' => '是否可见'],
            'min_level' => ['default' => 0, 'min' => 0, 'label' => '自动获得的最低等级'],
        ], 'name', 100, ['title_key' => $key], ['description' => 200, 'color' => 20]);
    }

    /** @param array<string, mixed> $body
     *  @return array{ok: true, in: array<string, mixed>, key: string, op: string}|array{ok: false, message: string} */
    private static function titleGrant(array $body, string $op): array
    {
        if (!in_array($op, ['grant', 'revoke'], true)) return self::no('授予头衔的 op 只能是 grant / revoke。');
        $user = trim((string) ($body['user_id'] ?? ''));
        $key  = trim((string) ($body['title_key'] ?? ''));
        if ($user === '' || $key === '') return self::no('user_id 与 title_key 都必填。');
        return ['ok' => true, 'in' => ['user_id' => $user, 'title_key' => $key, 'op' => $op],
            'key' => $user . ' / ' . $key, 'op' => $op];
    }

    /** @param array<string, mixed> $body
     *  @return array{ok: true, in: array<string, mixed>, key: string, op: string}|array{ok: false, message: string} */
    private static function levelDef(array $body, string $op): array
    {
        if (!in_array($op, ['upsert', 'delete'], true)) return self::no('等级定义的 op 只能是 upsert / delete。');
        $raw = trim((string) ($body['level'] ?? ''));
        if (self::int($raw) === null || (int) $raw < 1) return self::no('等级必须是 ≥ 1 的整数。');
        $level = (int) $raw;
        return self::definition($body, $op, (string) $level, [
            'min_xp' => ['default' => 0, 'min' => 0, 'label' => '经验门槛'],
        ], 'name', 100, ['level' => $level], ['color' => 20]);
    }

    /**
     * 定义类操作的公共骨架：删除要手写主键确认，其余字段逐个校验后组装。
     *
     * @param array<string, mixed> $body
     * @param array<string, array{default: int, min: int, max?: int, label: string}> $numbers
     * @param array<string, mixed> $base
     * @param array<string, int> $texts 额外文本字段 => 长度上限
     * @return array{ok: true, in: array<string, mixed>, key: string, op: string}|array{ok: false, message: string}
     */
    private static function definition(array $body, string $op, string $key, array $numbers,
        string $titleField, int $titleMax, array $base, array $texts = []): array
    {
        $in = $base + ['op' => $op];
        if ($op === 'delete') {
            $confirm = trim((string) ($body['confirm'] ?? ''));
            if ($confirm !== $key) {
                return self::no('删除需要确认：请在「确认标识」里原样手写 ' . $key
                    . ' 再点删除。规则 / 头衔 / 等级定义删掉后不影响已入账经验，但没法一键撤销。');
            }
            return ['ok' => true, 'in' => $in, 'key' => $key, 'op' => $op];
        }

        $title = trim((string) ($body[$titleField] ?? ''));
        if (self::tooLong($title, $titleMax)) {
            return self::no($titleField . ' 最多 ' . $titleMax . ' 字符。');
        }
        $in[$titleField] = $title;
        foreach ($texts as $field => $max) {
            $value = trim((string) ($body[$field] ?? ''));
            if (self::tooLong($value, $max)) return self::no($field . ' 最多 ' . $max . ' 字符。');
            $in[$field] = $value;
        }
        foreach ($numbers as $field => $rule) {
            $raw = trim((string) ($body[$field] ?? ''));
            if ($raw === '') { $in[$field] = $rule['default']; continue; }
            $value = self::int($raw);
            if ($value === null || $value < $rule['min'] || (isset($rule['max']) && $value > $rule['max'])) {
                $range = isset($rule['max']) ? $rule['min'] . '~' . $rule['max'] : '≥ ' . $rule['min'];
                return self::no($rule['label'] . '（' . $field . '）必须是整数，范围 ' . $range . '。');
            }
            $in[$field] = (int) $value;
        }
        return ['ok' => true, 'in' => $in, 'key' => $key, 'op' => $op];
    }

    private static function int(string $raw): ?int
    {
        return preg_match('/^-?\d+$/', $raw) === 1 ? (int) $raw : null;
    }

    private static function tooLong(string $value, int $max): bool
    {
        return \Wx\Systems\Data\Utf8Text::len($value) > $max;
    }

    /** @return array{ok: false, message: string} */
    private static function no(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }
}
