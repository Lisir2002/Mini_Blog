<?php
declare(strict_types=1);
namespace Wx\Pages\ContentAdmin;

use InvalidArgumentException;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Systems\Api\ApiOperationException;
use Wx\Systems\Api\Envelope;
use Wx\Core\Kernel;
use Wx\Contracts\AccessControlInterface;

/**
 * 内容生命周期写入口 —— 把「表单 → 操作」收敛成可穷举的纯逻辑。
 *
 * 与审核队列同构的三条纪律：
 *  1. 动作白名单之外一律 422，绝不把未知值当成某个默认操作；
 *  2. 身份只来自守卫验签后的结果，页面不兜底成匿名管理员；
 *  3. 失败码由接口层同一张表翻译（Envelope::statusOf），页面不发明第二套映射。
 *
 * 另加一条本页特有的：**按权限点裁剪按钮**。author 有 content.edit 但没有
 * content.publish / content.delete，渲染一个点了必然 403 的按钮，与「票据签不出来
 * 就不给按钮」是同一个原则。
 */
final class ContentAdminAction
{
    /** 动作 → 需要的权限点（页面据此裁剪按钮，接口层再判一次） */
    public const NEEDS = [
        'publish'  => 'content.publish',
        'draft'    => 'content.publish',
        'archive'  => 'content.publish',
        'trash'    => 'content.delete',
        'restore'  => 'content.delete',
        'purge'    => 'content.delete',
    ];

    /** @return array<string, bool> 动作 → 当前身份是否可用 */
    public static function allowed(Kernel $kernel, ?array $identity): array
    {
        $acl = self::acl($kernel);
        $out = [];
        foreach (self::NEEDS as $op => $permission) {
            $out[$op] = $acl !== null && $identity !== null && $acl->allows($identity, $permission);
        }
        return $out;
    }

    /** @param array<string, mixed> $body
     *  @return array{ok: bool, status: int, code: int, message: string} */
    public static function run(ApiRegistryInterface $registry, array $body, ?array $identity): array
    {
        $op   = trim((string) ($body['op'] ?? ''));
        $slug = trim((string) ($body['slug'] ?? ''));

        if ($slug === '') return self::fail(422, 42201, '文章 slug 缺失');
        if (!isset(self::NEEDS[$op])) {
            return self::fail(422, 42201, '操作只能是 ' . implode(' / ', array_keys(self::NEEDS))
                . '，当前为「' . ($op === '' ? '(空)' : $op) . '」');
        }
        if ($identity === null) {
            return self::fail(401, 40101, '身份未建立：表单票据未通过校验，请刷新页面后重试');
        }

        try {
            self::call($registry, $op, $slug, $body, $identity);
        } catch (\Throwable $e) {
            return self::fromThrowable($e);
        }
        return ['ok' => true, 'status' => 200, 'code' => 0, 'message' => 'ok'];
    }

    /** @param array<string, mixed> $body @param array<string, mixed> $identity */
    private static function call(ApiRegistryInterface $registry, string $op, string $slug,
        array $body, array $identity): void
    {
        $base = ['slug' => $slug, '_identity' => $identity];
        match ($op) {
            'publish', 'draft', 'archive' => $registry->execute('content.transition',
                $base + ['to' => $op === 'publish' ? 'published' : ($op === 'draft' ? 'draft' : 'archived')]),
            'trash'   => $registry->execute('content.delete', $base),
            'restore' => $registry->execute('content.restore', $base),
            // 彻底删除的二次确认必须是「人写出来的」slug，不能由页面代填。
            'purge'   => $registry->execute('content.purge', $base + ['confirm' => trim((string) ($body['confirm'] ?? ''))]),
        };
    }

    /** @return array{ok: bool, status: int, code: int, message: string} */
    public static function fromThrowable(\Throwable $e): array
    {
        if ($e instanceof ApiOperationException) {
            $status = Envelope::statusOf($e->apiCode);
            // 未登记的业务码在表里落到 500，那是「服务器内部错误」的误报：
            // 4xxxx 一律是请求问题（422），5xxxx 才是服务端问题。
            if ($status === 500 && $e->apiCode < 50000) $status = 422;
            return self::fail($status, $e->apiCode, $e->getMessage());
        }
        if ($e instanceof InvalidArgumentException) {
            return self::fail(422, 42201, $e->getMessage());
        }
        return self::fail(503, 50301, '内容操作失败：' . $e->getMessage());
    }

    private static function acl(Kernel $kernel): ?AccessControlInterface
    {
        $c = $kernel->container();
        return $c->has(AccessControlInterface::class) ? $c->resolve(AccessControlInterface::class) : null;
    }

    /** @return array{ok: bool, status: int, code: int, message: string} */
    private static function fail(int $status, int $code, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'code' => $code, 'message' => $message];
    }
}
