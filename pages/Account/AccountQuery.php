<?php
declare(strict_types=1);
namespace Wx\Pages\Account;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Kernel;

/**
 * 账号页取数 —— 当前身份由守卫在 route.access 阶段裁决，这里只做补全与降级。
 *
 * 只读两个接口：users.me（身份字段）与 auth.session.current（会话元数据）。
 * 任一不可用都返回 error，由页面渲染「读不到」而不是把空白当成「你没有资料」。
 */
final class AccountQuery
{
    /**
     * @param array<string, mixed> $identity
     * @return array{user: array<string, mixed>, session: array<string, mixed>, error: string}
     */
    public static function fetch(Kernel $kernel, array $identity): array
    {
        $container = $kernel->container();
        if (!$container->has(ApiRegistryInterface::class)) {
            return ['user' => [], 'session' => [], 'error' => '接口清单未装配，无法读取账号资料。'];
        }
        $registry = $container->resolve(ApiRegistryInterface::class);

        try {
            $me = $registry->execute('users.me', ['_identity' => $identity]);
            $current = $registry->execute('auth.session.current', ['_identity' => $identity]);
        } catch (\Throwable $e) {
            return ['user' => [], 'session' => [], 'error' => '账号资料读取失败：' . $e->getMessage()];
        }

        $user = (array) ($me['identity'] ?? []);
        if ($user === []) $user = $identity;
        // 账号表字段（display_name）与成员系统投影（member.display_name）可能只有一份：
        // 页面只认顶层，缺了就补上，避免「保存成功但页面仍显示未设置」。
        if (($user['display_name'] ?? '') === '') {
            $user['display_name'] = (string) (($user['member'] ?? [])['display_name'] ?? '');
        }
        return [
            'user'    => $user,
            'session' => (array) ($current['session'] ?? []),
            'error'   => '',
        ];
    }
}
