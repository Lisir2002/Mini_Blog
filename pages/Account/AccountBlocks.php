<?php
declare(strict_types=1);
namespace Wx\Pages\Account;

/**
 * 账号页区块组装 —— 纯函数，无 IO。
 *
 * 票据签不出来时不渲染任何写表单：一个点了必然 401 的按钮，比没有按钮更糟。
 * 读失败（error 非空）只影响资料摘要，不把整页变成空白 —— 表单仍需可用，
 * 但会在页首明确写「读不到」，不让用户以为资料被清空了。
 */
final class AccountBlocks
{
    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $session
     * @param array<string, mixed>|null $error
     * @return list<array<string, mixed>>
     */
    public static function build(array $user, array $session, string $ticket,
        string $readError, ?array $error, string $notice): array
    {
        $blocks = [];
        if ($notice !== '') {
            $blocks[] = ['type' => 'notice', 'id' => 'account-notice', 'tone' => 'ok',
                'state' => 'done', 'title' => '已保存', 'value' => $notice];
        }
        if ($error !== null) {
            $blocks[] = ['type' => 'notice', 'id' => 'account-error', 'tone' => 'warn',
                'state' => 'failed', 'title' => '没有保存',
                'value' => (string) ($error['message'] ?? '请检查填写内容后重试。')];
        }
        if ($readError !== '') {
            $blocks[] = ['type' => 'notice', 'id' => 'account-read', 'tone' => 'warn',
                'state' => 'unavailable', 'title' => '资料读取失败', 'value' => $readError];
        }

        $blocks[] = ['type' => 'table', 'id' => 'account-summary', 'title' => '当前身份', 'rows' => [
            ['k' => '用户名', 'v' => (string) ($user['username'] ?? '(未知)'), 'ok' => true],
            ['k' => '显示名', 'v' => (string) ($user['display_name'] ?? '(未设置)')],
            ['k' => '角色', 'v' => (string) ($user['role'] ?? '(未知)')],
            ['k' => '会话到期', 'v' => (string) ($session['expires_at'] ?? '(未接入会话表)')],
            ['k' => '凭据类型', 'v' => (string) ($session['kind'] ?? 'session')],
        ]];

        if ($ticket === '') {
            $blocks[] = ['type' => 'notice', 'id' => 'account-ticket', 'tone' => 'warn',
                'state' => 'unavailable', 'title' => '暂时不能修改',
                'value' => '表单票据签发失败（storage/form-ticket.key 不可写）。'
                    . '为避免提交后必然 401，这里不显示写表单；请让运维检查 storage 权限。'];
            return $blocks;
        }

        $hidden = [['name' => '_form_ticket', 'value' => $ticket]];
        $blocks[] = [
            'type' => 'form', 'id' => 'account-profile', 'title' => '修改显示名',
            'action' => '/account', 'method' => 'post',
            'hidden' => array_merge($hidden, [['name' => 'op', 'value' => 'profile']]),
            'fields' => [
                ['tag' => 'input', 'id' => 'display_name', 'name' => 'display_name', 'type' => 'text',
                 'value' => (string) ($user['display_name'] ?? ''), 'placeholder' => '显示名，最多 64 字'],
                ['tag' => 'submit', 'label' => '保存显示名'],
            ],
            'note' => '显示名只影响展示，不改变登录用户名。',
        ];
        $blocks[] = [
            'type' => 'form', 'id' => 'account-password', 'title' => '修改口令',
            'action' => '/account', 'method' => 'post',
            'hidden' => array_merge($hidden, [['name' => 'op', 'value' => 'password']]),
            'fields' => [
                ['tag' => 'input', 'id' => 'old_password', 'name' => 'old_password',
                 'type' => 'password', 'placeholder' => '当前口令'],
                ['tag' => 'input', 'id' => 'new_password', 'name' => 'new_password',
                 'type' => 'password', 'placeholder' => '新口令：至少 8 位'],
                ['tag' => 'input', 'id' => 'confirm_password', 'name' => 'confirm_password',
                 'type' => 'password', 'placeholder' => '再输一次新口令'],
                ['tag' => 'submit', 'label' => '更新口令'],
            ],
            'note' => '改密后保留当前会话，其他设备上的会话全部撤销。',
        ];
        $blocks[] = [
            'type' => 'form', 'id' => 'account-logout', 'title' => '退出登录',
            'action' => '/account', 'method' => 'post',
            'hidden' => array_merge($hidden, [['name' => 'op', 'value' => 'logout']]),
            'fields' => [['tag' => 'submit', 'label' => '退出当前会话']],
            'note' => '会撤销当前会话令牌并清除 Cookie；API 脚本令牌不受影响。',
        ];
        $blocks[] = ['type' => 'links', 'id' => 'account-nav', 'title' => '去别处', 'items' => [
            ['label' => '首页', 'href' => '/'],
            ['label' => '控制台', 'href' => '/console'],
        ]];
        return $blocks;
    }
}
