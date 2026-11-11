<?php
declare(strict_types=1);
namespace Wx\Pages\Member;

/**
 * 把执行者的返回值翻译成「实际发生了什么」—— 这一页最容易静默出错的地方。
 *
 * 发经验的「成功」有五种不同含义，一律说「已发放」等于骗人：
 * 真加了分 / event_key 重放 / 每日上限截断为 0 / 规则停用 / 只加了一部分。
 * 定义类操作的 changed=false 也有两种：本来就这个值，或要删的东西本来就不存在。
 * 这些差别接口用不同字段表达了，页面必须把它说出来，而不是折成一句「完成」。
 */
final class MemberOutcome
{
    /**
     * @param array<string, mixed> $out 执行者返回值
     * @param array<string, mixed> $in  校验后的请求参数
     * @return array{outcome: string, changed: bool, detail: string}
     */
    public static function explain(string $action, array $out, array $in, string $key): array
    {
        if ($action === 'award') return self::award($out, $in, $key);
        $changed = (bool) ($out['changed'] ?? false);
        $op = (string) ($in['op'] ?? '');
        if (!$changed) {
            return ['outcome' => 'no-change', 'changed' => false,
                'detail' => self::noChange($action, $op, $key)];
        }
        return ['outcome' => $op, 'changed' => true, 'detail' => self::changed($action, $op, $key)];
    }

    /**
     * @param array<string, mixed> $out
     * @param array<string, mixed> $in
     * @return array{outcome: string, changed: bool, detail: string}
     */
    private static function award(array $out, array $in, string $key): array
    {
        $applied  = (int) ($out['applied'] ?? 0);
        $total    = (int) ($out['total'] ?? 0);
        $level    = (int) ($out['level'] ?? 0);
        $reason   = (string) ($out['reason'] ?? '');
        $up       = (bool) ($out['level_up'] ?? false);
        $requested = isset($in['amount']) ? (int) $in['amount'] : null;
        $tail = '当前 ' . $total . ' 经验 / Lv.' . $level . ($up ? '（本次升级）' : '（等级未变）') . '。';

        if ($reason === 'duplicate') {
            return ['outcome' => 'duplicate', 'changed' => false,
                'detail' => 'event_key「' . (string) ($in['event_key'] ?? '') . '」已经记过账，'
                    . '本次没有重复加分 —— 这是幂等在生效，不是失败。' . $tail];
        }
        if ($reason === 'daily_cap') {
            return ['outcome' => 'daily_cap', 'changed' => false,
                'detail' => '该动作今天已达每日上限，本次加 0 分（接口按设计截断，不报错）。' . $tail];
        }
        if ($reason === 'disabled') {
            return ['outcome' => 'disabled', 'changed' => false,
                'detail' => '这个动作的经验规则被停用，本次加 0 分。' . $tail];
        }
        if ($applied <= 0) {
            return ['outcome' => 'zero', 'changed' => false,
                'detail' => '本次实际加了 0 分。' . $tail];
        }
        if ($requested !== null && $applied < $requested) {
            return ['outcome' => 'truncated', 'changed' => true,
                'detail' => '实际只加了 ' . $applied . ' 分（请求 ' . $requested
                    . ' 分）：每日上限截断了余下 ' . ($requested - $applied) . ' 分。' . $tail];
        }
        return ['outcome' => 'applied', 'changed' => true,
            'detail' => '给 ' . $key . ' 加了 ' . $applied . ' 分。' . $tail
                . '未显式填 amount 时按经验规则取值，仍可能被每日上限截断 —— 以这里的实际值为准。'];
    }

    private static function noChange(string $action, string $op, string $key): string
    {
        if ($op === 'delete') {
            return '「' . $key . '」本来就不存在，没有可删的。'
                . '注意这也可能是标识写错了 —— 接口对删除不存在的定义不报错。';
        }
        if ($op === 'grant') return $key . ' 本来就已经授予了，没有重复写入。';
        if ($op === 'revoke') return $key . ' 本来就没有这条手动授予，没有变化。'
            . '如果这个头衔是靠等级自动获得的，撤销手动授予不会让它消失。';
        return '「' . $key . '」本来就是这个值，没有变化（upsert 幂等）。';
    }

    private static function changed(string $action, string $op, string $key): string
    {
        return match ($op) {
            'delete' => '已删除「' . $key . '」。规则 / 头衔 / 等级定义只影响以后的解释与发放，'
                . '不回溯已入账经验。',
            'grant'  => '已给 ' . $key . ' 授予头衔。头衔不参与任何权限决策。',
            'revoke' => '已撤销 ' . $key . ' 的手动授予。若头衔由等级自动获得，档案里仍会显示。',
            default  => '已保存「' . $key . '」。',
        };
    }
}
