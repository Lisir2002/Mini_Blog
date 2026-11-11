<?php
declare(strict_types=1);
namespace Wx\Pages\Member;

/**
 * 五张写表单的字段清单 —— 纯数据，不放渲染逻辑。
 *
 * 单独成文件的原因：字段说明本身就要把接口参数、格式约束、危险操作的确认方式
 * 写清楚，这部分比编排代码还长；混在 MemberForms 里会让那个文件越过职责预算。
 *
 * 每条 hint 都对应 MemberInput 的一条校验规则，两处必须同时改 ——
 * 表单说「可留空」而校验说「必填」是最容易被忽略的静默出错。
 */
final class MemberFormSpec
{
    /** @return array{intro: string, note: string, fields: list<array{name: string, hint: string}>,
     *                submit: list<array<string, string>>} */
    public static function spec(string $panel): array
    {
        return match ($panel) {
            'award' => [
                'intro' => 'user_id 可填用户名或数字 ID；动作是经验规则里的 action；'
                    . 'event_key 标识「同一件业务事实」，同一个键重复提交不会重复加分，想再发一次请换一个键。',
                'fields' => [
                    ['name' => 'user_id', 'hint' => '用户 ID 或用户名（必填）'],
                    ['name' => 'xp_action', 'hint' => '动作 action，如 content.publish（必填）'],
                    ['name' => 'event_key', 'hint' => '幂等键 event_key，如 post-42-published（必填）'],
                    ['name' => 'amount', 'hint' => '经验值（可留空 = 按规则取值）'],
                    ['name' => 'note', 'hint' => '备注（可留空）'],
                ],
                'submit' => [['tag' => 'submit', 'name' => 'op', 'value' => 'award', 'label' => '发放经验']],
                'note' => '没有经验规则时必须显式填 amount；每日上限会截断而不是报错；'
                    . '规则停用或 event_key 重放时本次加 0 分 —— 提交后会如实回报实际加了多少。'
                    . '纯数字 ID 不校验账号是否存在，可能凭空建一份档案。',
            ],
            'xp_rule' => [
                'intro' => '规则决定「这个动作每次值多少分、每天最多拿多少」。'
                    . '它只影响以后的发放，不回溯已入账的经验；调低上限也不扣回历史分。',
                'fields' => [
                    ['name' => 'rule_action', 'hint' => '动作 action（必填，小写字母 / 数字 / . _ -）'],
                    ['name' => 'amount', 'hint' => '单次经验（默认 0）'],
                    ['name' => 'daily_cap', 'hint' => '每日上限（0 = 不限）'],
                    ['name' => 'enabled', 'hint' => '1 启用 / 0 停用（默认 1）'],
                    ['name' => 'description', 'hint' => '说明（可留空）'],
                    ['name' => 'confirm', 'hint' => '删除时原样手写动作名'],
                ],
                'submit' => self::saveDelete(),
                'note' => '删除规则后，依赖该动作的发经验调用必须显式填 amount，否则会被拒绝。'
                    . '删除需要手写确认，因为按钮并排时误点没有撤销机会。',
            ],
            'title_def' => [
                'intro' => '定义头衔本身。visible=0 是「不默认展示」，不是访问控制；'
                    . 'min_level > 0 时达到该等级的用户自动拥有，不写授予表，门槛调高后自动消失。',
                'fields' => [
                    ['name' => 'title_key', 'hint' => '头衔标识 title_key（必填）'],
                    ['name' => 'name', 'hint' => '显示名称'],
                    ['name' => 'description', 'hint' => '说明'],
                    ['name' => 'color', 'hint' => '颜色（可留空）'],
                    ['name' => 'priority', 'hint' => '优先级（默认 0）'],
                    ['name' => 'visible', 'hint' => '1 可见 / 0 隐藏（默认 1）'],
                    ['name' => 'min_level', 'hint' => '自动获得的最低等级（0 = 仅手动授予）'],
                    ['name' => 'confirm', 'hint' => '删除时原样手写 title_key'],
                ],
                'submit' => self::saveDelete(),
                'note' => '头衔不参与任何权限决策。删除定义后，已手动授予的关系仍留在库里，'
                    . '但档案里不再显示 —— 要清干净得逐人撤销。',
            ],
            'title_grant' => [
                'intro' => '把已定义的头衔授予 / 撤销给某个人。'
                    . '页面会先查目录：授予一个不存在的 title_key 不会报错，但档案里永远看不到。',
                'fields' => [
                    ['name' => 'user_id', 'hint' => '用户 ID 或用户名（必填）'],
                    ['name' => 'title_key', 'hint' => '头衔标识（必填，须在目录里）'],
                ],
                'submit' => [
                    ['tag' => 'submit', 'name' => 'op', 'value' => 'grant', 'label' => '授予'],
                    ['tag' => 'submit', 'name' => 'op', 'value' => 'revoke', 'label' => '撤销手动授予'],
                ],
                'note' => '撤销只删手动授予：靠等级自动获得的头衔仍在档案里显示，这是刻意设计。'
                    . '头衔不影响权限决策。',
            ],
            'level_def' => [
                'intro' => '等级是「经验 + 门槛」的解释结果，不是写死在用户身上的字段。'
                    . '改门槛不跑批处理，下次读档案自然按新表算；删掉某级后，落在该级的人按相邻等级重新解释。',
                'fields' => [
                    ['name' => 'level', 'hint' => '等级序号（必填，≥ 1）'],
                    ['name' => 'name', 'hint' => '等级名称'],
                    ['name' => 'min_xp', 'hint' => '经验门槛（默认 0）'],
                    ['name' => 'color', 'hint' => '颜色（可留空）'],
                    ['name' => 'confirm', 'hint' => '删除时原样手写等级序号'],
                ],
                'submit' => self::saveDelete(),
                'note' => '接口不阻止跳号或门槛重复：结构合法但语义可疑。'
                    . '本页上方的等级表会给出断点告警，但不会替你改写配置。',
            ],
        };
    }

    /** @return list<array<string, string>> */
    private static function saveDelete(): array
    {
        return [
            ['tag' => 'submit', 'name' => 'op', 'value' => 'upsert', 'label' => '保存 / 新建'],
            ['tag' => 'submit', 'name' => 'op', 'value' => 'delete', 'label' => '删除（需确认）'],
        ];
    }
}
