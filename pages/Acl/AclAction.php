<?php
declare(strict_types=1);
namespace Wx\Pages\Acl;

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Pages\Shared\ApiFailure;

/**
 * 资源 ACL 与用户组的写入口 —— 页面层再问一次 permission.manage。
 *
 * 页面声明的是 permission.read（能看），写动作在这里现场裁决；页面直调执行者
 * 会绕过 API 分发器，权限层缺席即 403。与 /console/roles 同一套边界。
 *
 * 接口本身不校验存在性（文章 id、角色名、账号、分组标识），所以这里先预查
 * content.post / permission.roles / permission.catalog / users.view，
 * 把「写错但不报错」的输入挡在写库之前。`users.view` 就是在这个位置被消费的。
 */
final class AclAction
{
    /** @param array<string, mixed>|null $identity
     *  @return array<string, mixed> */
    public static function run(Kernel $kernel, Request $request, ?array $identity): array
    {
        $body  = $request->body();
        $panel = trim((string) ($body['panel'] ?? ''));
        if (!in_array($panel, ['acl', 'groups'], true)) {
            return self::fail(422, 42201, '只有资源 ACL 与用户组两个面板接受提交，当前为「' . $panel . '」。');
        }
        if ($identity === null) return self::fail(401, 40101, '身份未建立，请刷新页面后重试。');

        $container = $kernel->container();
        $acl = $container->has(AccessControlInterface::class)
            ? $container->resolve(AccessControlInterface::class) : null;
        if ($acl === null || !$acl instanceof AccessControlInterface
            || !$acl->allows($identity, 'permission.manage')) {
            return self::fail(403, 40303, '缺少 permission.manage 权限点，写操作被拒绝（fail-closed）。'
                . '能看授权不等于能改授权。');
        }
        if (!$container->has(ApiRegistryInterface::class)) {
            return self::fail(503, 50301, '接口清单未装配，无法提交。');
        }
        $registry = $container->resolve(ApiRegistryInterface::class);
        $facts = self::facts($registry, $panel, $body);

        $input = AclInput::normalize($panel, $body, $facts);
        if (($input['ok'] ?? false) !== true) {
            return self::fail(422, 42201, (string) ($input['message'] ?? '参数不合法。'));
        }
        try {
            $out = $registry->execute((string) $input['operation'],
                (array) $input['in'] + ['_identity' => $identity]);
        } catch (\Throwable $e) {
            return ApiFailure::describe($e);
        }

        $changed = (bool) ($out['changed'] ?? false);
        $detail = (string) $input['detail'];
        if (!$changed) $detail .= ' ' . self::noChange($panel, (string) ($input['in']['op'] ?? ''));
        return ['ok' => true, 'status' => 200, 'code' => 0, 'message' => 'ok',
            'panel' => $panel, 'outcome' => $changed ? 'changed' : 'no-change',
            'changed' => $changed, 'detail' => $detail, 'hint' => (string) $input['hint'],
            'slug' => (string) ($body['slug'] ?? ''), 'group' => (string) ($body['group'] ?? '')];
    }

    /**
     * 预查：把「接口不校验」的四类标识交给页面层核对。
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function facts(ApiRegistryInterface $registry, string $panel, array $body): array
    {
        $facts = ['roles' => [], 'catalog' => [], 'post_ok' => false, 'user_ok' => false];
        try {
            $facts['roles'] = (array) ($registry->execute('permission.roles', [])['roles'] ?? []);
            $facts['catalog'] = (array) ($registry->execute('permission.catalog', [])['permissions'] ?? []);
        } catch (\Throwable $e) {
            return $facts; // 读不到清单时所有存在性校验都会失败 —— 这正是 fail-closed
        }
        if ($panel === 'acl') {
            $slug = trim((string) ($body['slug'] ?? ''));
            try {
                $registry->execute('content.post', ['slug' => $slug]);
                $facts['post_ok'] = true;
            } catch (\Throwable $e) {
                $facts['post_ok'] = false;
            }
        }
        $candidate = trim((string) ($body['subject'] ?? ''));
        if (preg_match('/^user:(\d+)$/', $candidate, $m) === 1) {
            try {
                $registry->execute('users.view', ['key' => $m[1]]);
                $facts['user_ok'] = true;
            } catch (\Throwable $e) {
                $facts['user_ok'] = false;
            }
        }
        return $facts;
    }

    /** `changed=false` 分场景，避免把「写错了」说成「已经生效」。 */
    private static function noChange(string $panel, string $op): string
    {
        if ($panel === 'acl') return '接口没有写入新记录（changed=false）。';
        return match ($op) {
            'upsert' => '这条分组定义本来就是这个值（upsert 幂等），没有变化。',
            'add_role', 'add_member' => '本来就已经绑定了，没有重复写入。',
            'remove_role', 'remove_member' => '本来就没有这条绑定，没有变化。',
            'delete' => '分组本来就不存在，没有可删的 —— 也可能是标识拼错了。',
            default => '没有发生变化。',
        };
    }

    /** @return array{ok: false, status: int, code: int, message: string} */
    private static function fail(int $status, int $code, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'code' => $code, 'message' => $message];
    }
}
