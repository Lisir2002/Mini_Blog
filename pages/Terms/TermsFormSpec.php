<?php
declare(strict_types=1);
namespace Wx\Pages\Terms;

/**
 * 两张写表单的字段规格 —— 纯数据，不含组装逻辑。
 *
 * 独立成文件不是为了「拆文件」，是为了让 TermsForms 的每一行都在讲权限与
 * 状态裁剪，而不是在重复字段声明。字段与 TermsInput 的校验一一对应。
 */
final class TermsFormSpec
{
    /** @return list<array{tag: string, id: string, name: string, placeholder: string}> */
    public static function termFields(): array
    {
        return [
            ['tag' => 'input', 'id' => 't-term-slug', 'name' => 'term_slug',
                'placeholder' => '术语 slug：小写字母 / 数字 / 连字符（新建用）'],
            ['tag' => 'input', 'id' => 't-term-name', 'name' => 'name',
                'placeholder' => '显示名称，最长 60 字（新建 / 改名）'],
            ['tag' => 'input', 'id' => 't-term-desc', 'name' => 'description',
                'placeholder' => '说明，最长 200 字（新建 / 改说明）'],
            ['tag' => 'input', 'id' => 't-term-id', 'name' => 'id',
                'placeholder' => '术语 ID：改名 / 删除用，见上表 id 列'],
            ['tag' => 'input', 'id' => 't-term-confirm', 'name' => 'confirm',
                'placeholder' => '删除确认：原样手写术语 slug'],
        ];
    }

    /** @return list<array{tag: string, name: string, value: string, label: string}> */
    public static function termButtons(): array
    {
        return [
            ['tag' => 'submit', 'name' => 'op', 'value' => 'create_category', 'label' => '新建分类'],
            ['tag' => 'submit', 'name' => 'op', 'value' => 'create_tag', 'label' => '新建标签'],
            ['tag' => 'submit', 'name' => 'op', 'value' => 'term_update', 'label' => '更新名称 / 说明'],
            ['tag' => 'submit', 'name' => 'op', 'value' => 'term_delete', 'label' => '删除术语（需确认）'],
        ];
    }

    public static function termNote(): string
    {
        return 'kind 与 slug 创建后不可修改：改 slug 会让所有旧链接 404，需要换标识就新建一个再迁移。'
            . '同 kind 下 slug 重复返回 40911 而不是静默复用 —— 复用会把新建变成把文章挂到别人的术语上。'
            . '删除会在事务里清掉该术语下所有文章的绑定，没有撤销入口，所以要求手写 slug 确认。'
            . '写这个表单需要 content.manage_terms。';
    }

    public static function attachNote(): string
    {
        return '框里是这篇文章**当前**已挂的术语，提交的是完整集合而不是增量：'
            . '删掉一行就是解绑，留空就是清空全部（需手写文章 slug 确认）。'
            . '只接受已存在的 category:slug / tag:slug，拼错会返回 40404 而不是新建一个只有一篇文章的词条；'
            . '一次请求里只要有一个引用不存在，整个操作失败，不会出现部分成功。'
            . '写这个表单需要 content.edit，与术语管理是两个不同的权限点。';
    }
}
