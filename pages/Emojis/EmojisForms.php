<?php
declare(strict_types=1);
namespace Wx\Pages\Emojis;

/**
 * 两张写表单 —— 登记一条自定义表情，以及每条自定义表情各自的删除表单。
 *
 * 内置表情不在 custom 清单里，因此根本不会生成删除按钮；这比渲染一个点了必然
 * 40901 的按钮诚实。删除要求手写短码确认，因为删掉后历史正文里的 :code: 不再渲染。
 */
final class EmojisForms
{
    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    public static function build(array $data): array
    {
        $ticket = (string) ($data['ticket'] ?? '');
        if ($ticket === '' || ($data['can_manage'] ?? false) !== true) {
            return [['type' => 'notice', 'id' => 'emojis-no-write', 'tone' => 'info',
                'state' => 'permission-denied', 'title' => '写入口不可用',
                'value' => '登记 / 删除表情要求 emoji.manage：能看目录不等于能改表情表。'
                    . '票据签不出时同样不给表单 —— 上面的只读目录仍然有效。']];
        }
        if (($data['state'] ?? '') === 'failed') {
            return [['type' => 'notice', 'id' => 'emojis-write-wait', 'tone' => 'warn',
                'state' => 'unavailable', 'title' => '写入口暂时关闭',
                'value' => '目录读不到就无法核对短码占用，不能给一个会撞 40901 的表单。']];
        }
        $out = [self::register($data)];
        foreach ((array) ($data['custom'] ?? []) as $e) {
            if (!is_array($e)) continue;
            $out[] = self::delete($data, $e);
        }
        return $out;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function register(array $data): array
    {
        $form = (array) ($data['form'] ?? []);
        return [
            'type' => 'form', 'title' => '登记自定义表情',
            'body' => '只接受本站已上传的图片附件 id，不接受 URL：外链表情会让每次浏览都'
                . '向第三方发请求，也能被随时替换。短码 2–32 位小写字母 / 数字 / _ + -。',
            'action' => '/console/emojis', 'method' => 'post',
            'hidden' => self::hidden($data, [['name' => 'op', 'value' => 'register']]),
            'fields' => [
                ['tag' => 'input', 'id' => 'emoji-code', 'name' => 'code', 'type' => 'text',
                    'value' => (string) ($form['code'] ?? ''), 'placeholder' => '短码，如 shipit'],
                ['tag' => 'input', 'id' => 'emoji-att', 'name' => 'attachment_id', 'type' => 'text',
                    'value' => (string) ($form['attachment_id'] ?? ''), 'placeholder' => '图片附件 id（正整数）'],
                ['tag' => 'submit', 'label' => '登记'],
            ],
            'note' => '页面先按全量目录核对短码占用（40901 不复用）；接口再复核附件存在且为 image/*。'
                . '可在 /console/attachments 按文章找到附件 id。',
        ];
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $e @return array<string, mixed> */
    private static function delete(array $data, array $e): array
    {
        $code = strtolower(trim((string) ($e['code'] ?? '')));
        $aid  = (int) ($e['attachment_id'] ?? 0);
        $url  = (string) ($e['url'] ?? '');
        $form = (array) ($data['form'] ?? []);
        return [
            'type' => 'form', 'title' => '删除 :' . $code . ':（附件 #' . $aid . '）',
            'body' => '请手写短码 ' . $code . ' 确认。正文存的是 :' . $code . ': 原文、渲染时才解析，'
                . '删除不会重写历史：读者仍看到字面量，只是不再渲染成图片。',
            'action' => '/console/emojis', 'method' => 'post',
            'hidden' => self::hidden($data, [
                ['name' => 'op', 'value' => 'delete'],
                ['name' => 'code', 'value' => $code],
            ]),
            'href' => $url, 'href_label' => '预览图片',
            'fields' => [
                ['tag' => 'input', 'id' => 'emoji-confirm-' . $code, 'name' => 'confirm', 'type' => 'text',
                    'value' => (string) ($form['confirm'] ?? ''), 'placeholder' => '手写短码 ' . $code . ' 确认'],
                ['tag' => 'submit', 'label' => '确认删除'],
            ],
            'note' => '删除动作现场问 emoji.manage；内置表情不在此列，也不可删除。',
        ];
    }

    /** @param array<string, mixed> $data @param list<array<string, string>> $extra
     *  @return list<array<string, string>> */
    private static function hidden(array $data, array $extra): array
    {
        $out = [['name' => '_form_ticket', 'value' => (string) ($data['ticket'] ?? '')],
            ['name' => 'kind', 'value' => (string) ($data['kind'] ?? 'any')]];
        foreach ($extra as $e) $out[] = $e;
        return $out;
    }
}
