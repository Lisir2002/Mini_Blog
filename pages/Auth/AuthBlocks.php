<?php
declare(strict_types=1);
namespace Wx\Pages\Auth;

/**
 * 登录 / 注册页的区块组装 —— 纯函数，无 IO。
 *
 * 四态纪律：正常表单、提交失败（保留已填用户名、不回显口令）、注册关闭、
 * 刚注册成功待登录。四种都必须是不同文案，不能都长成一张空表单。
 */
final class AuthBlocks
{
    /** @param array<string, string> $values @param array<string, mixed>|null $error */
    public static function login(array $values, ?array $error, string $notice, string $next): array
    {
        $blocks = [];
        if ($notice !== '') {
            $blocks[] = ['type' => 'notice', 'id' => 'auth-notice', 'tone' => 'ok',
                'state' => 'done', 'title' => '账号已创建', 'value' => $notice];
        }
        if ($error !== null) $blocks[] = self::error($error);

        $blocks[] = [
            'type' => 'form', 'id' => 'login-form', 'title' => '登录',
            'action' => '/login', 'method' => 'post',
            'hidden' => [['name' => 'next', 'value' => $next]],
            'fields' => [
                ['tag' => 'input', 'id' => 'username', 'name' => 'username', 'type' => 'text',
                 'value' => $values['username'] ?? '', 'placeholder' => '用户名'],
                ['tag' => 'input', 'id' => 'password', 'name' => 'password', 'type' => 'password',
                 'placeholder' => '口令'],
                ['tag' => 'submit', 'name' => 'op', 'value' => 'login', 'label' => '登录'],
            ],
            'note' => '登录成功后回到你刚才访问的页面；会话凭据只写入 HttpOnly Cookie，脚本读不到。',
        ];
        $blocks[] = ['type' => 'links', 'id' => 'auth-switch', 'title' => '还没有账号', 'items' => [
            ['label' => '注册', 'href' => '/register', 'note' => '需要站点开放注册'],
        ]];
        return $blocks;
    }

    /** @param array<string, string> $values @param array<string, mixed>|null $error */
    public static function register(array $values, ?array $error, bool $closed): array
    {
        if ($closed) {
            return [
                ['type' => 'notice', 'id' => 'auth-notice', 'tone' => 'warn', 'state' => 'closed',
                 'title' => '注册未开放',
                 'value' => '本站当前关闭了自助注册（user.registration.enabled=false）。'
                     . '请联系管理员开通账号，或使用已有账号登录。'],
                ['type' => 'links', 'id' => 'auth-switch', 'title' => '已有账号', 'items' => [
                    ['label' => '去登录', 'href' => '/login'],
                ]],
            ];
        }
        $blocks = [];
        if ($error !== null) $blocks[] = self::error($error);

        $blocks[] = [
            'type' => 'form', 'id' => 'register-form', 'title' => '注册',
            'action' => '/register', 'method' => 'post',
            'fields' => [
                ['tag' => 'input', 'id' => 'username', 'name' => 'username', 'type' => 'text',
                 'value' => $values['username'] ?? '', 'placeholder' => '用户名：3~32 位字母、数字、下划线'],
                ['tag' => 'input', 'id' => 'display_name', 'name' => 'display_name', 'type' => 'text',
                 'value' => $values['display_name'] ?? '', 'placeholder' => '显示名（可留空）'],
                ['tag' => 'input', 'id' => 'password', 'name' => 'password', 'type' => 'password',
                 'placeholder' => '口令：至少 8 位，不能与用户名相同'],
                ['tag' => 'submit', 'name' => 'op', 'value' => 'register', 'label' => '注册并登录'],
            ],
            'note' => '注册成功后直接建立浏览器会话；口令经过 bcrypt 哈希后入库，页面与日志都不保留明文。',
        ];
        $blocks[] = ['type' => 'links', 'id' => 'auth-switch', 'title' => '已有账号', 'items' => [
            ['label' => '去登录', 'href' => '/login'],
        ]];
        return $blocks;
    }

    /** @param array<string, mixed> $error */
    private static function error(array $error): array
    {
        return ['type' => 'notice', 'id' => 'auth-error', 'tone' => 'warn', 'state' => 'failed',
            'title' => '没有完成', 'value' => (string) ($error['message'] ?? '请检查填写内容后重试。')];
    }
}
