<?php
declare(strict_types=1);
namespace Wx\Pages\ContentAdmin;

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Kernel;

/**
 * 发布排期与推荐位的写入口 —— 补的是「接口已消费、参数却没入口」这一层。
 *
 * content.transition 早被本页的发布 / 转草稿 / 归档按钮消费，但从没人传过
 * publish_at；content.update 早被写作页消费，但只传 title / body / status，
 * pinned / weight 一直没有人能设置。消费矩阵看到的是 operation 名，看不到参数，
 * 所以「零消费归零」也拦不住这类缺口。
 *
 * 三条边界：
 *  1. 页面直调执行器绕过 API 分发器，权限必须在这一层现场再问一次（fail-closed）；
 *     按钮裁掉只是不给假按钮，不是真边界。
 *  2. 置顶状态由两个提交按钮枚举，不用 checkbox —— 主题模板不输出 checked，
 *     未勾选的框会把「已置顶」误提交成「取消置顶」。与附件可见性同一手法。
 *  3. 时间原样交给执行器解析（它拒绝无法解析的值，绝不静默变成立即发布）；
 *     权重范围显式拒绝，不静默 clamp 到边界。
 */
final class ContentAdminPlacement
{
    public const OPS = ['schedule', 'pin:on', 'pin:off'];

    /** 动作 → 需要的权限点；与 ContentAdminAction 的按钮裁剪同一张表语义。 */
    private const NEEDS = [
        'schedule' => 'content.publish',
        'pin:on'   => 'content.edit',
        'pin:off'  => 'content.edit',
    ];

    /** @return array{can_schedule: bool, can_pin: bool} */
    public static function capabilities(Kernel $kernel, ?array $identity): array
    {
        $acl = self::acl($kernel);
        $can = static fn (string $point): bool => $acl !== null && $identity !== null
            && $acl->allows($identity, $point);
        return ['can_schedule' => $can('content.publish'), 'can_pin' => $can('content.edit')];
    }

    /** @param array<string, mixed> $body
     *  @param array<string, mixed>|null $identity
     *  @return array{ok: bool, status: int, code: int, message: string} */
    public static function run(Kernel $kernel, ApiRegistryInterface $registry,
        array $body, ?array $identity): array
    {
        $op   = trim((string) ($body['op'] ?? ''));
        $slug = trim((string) ($body['slug'] ?? ''));

        if (!in_array($op, self::OPS, true)) {
            return self::fail(422, 42201, '动作只能由表单按钮给出（'
                . implode(' / ', self::OPS) . '），当前为「' . ($op === '' ? '(空)' : $op) . '」');
        }
        if ($slug === '' || preg_match('/^[a-z0-9][a-z0-9-]{0,190}$/', $slug) !== 1) {
            return self::fail(422, 42201, '缺少合法的文章 slug');
        }
        if ($identity === null) {
            return self::fail(401, 40101, '身份未建立：表单票据未通过校验，请刷新页面后重试');
        }

        $point = self::NEEDS[$op];
        $acl = self::acl($kernel);
        if ($acl === null || !$acl->allows($identity, $point)) {
            return self::fail(403, 40303, '缺少 ' . $point . ' 权限点，操作被拒绝（fail-closed）');
        }

        try {
            self::call($registry, $op, $slug, $body, $identity);
        } catch (\Throwable $e) {
            return ContentAdminAction::fromThrowable($e);
        }
        return ['ok' => true, 'status' => 200, 'code' => 0, 'message' => 'ok'];
    }

    /** @param array<string, mixed> $body @param array<string, mixed> $identity */
    private static function call(ApiRegistryInterface $registry, string $op, string $slug,
        array $body, array $identity): void
    {
        $base = ['slug' => $slug, '_identity' => $identity];
        if ($op === 'schedule') {
            $raw = $body['publish_at'] ?? '';
            if (!is_string($raw)) {
                throw new \InvalidArgumentException('发布时间必须是文本或留空');
            }
            // 空串 = 取消排期并立即发布；其余原样交给执行器，由它拒绝解析不了的值。
            $registry->execute('content.transition', $base + [
                'to' => 'published', 'publish_at' => trim($raw) === '' ? null : trim($raw),
            ]);
            return;
        }

        $weight = trim((string) ($body['weight'] ?? ''));
        if (preg_match('/^-?\d+$/', $weight) !== 1) {
            throw new \InvalidArgumentException('权重必须是整数（-1000~1000），当前为「'
                . ($weight === '' ? '(空)' : $weight) . '」');
        }
        $number = (int) $weight;
        if ($number < -1000 || $number > 1000) {
            throw new \InvalidArgumentException('权重范围是 -1000~1000，当前 ' . $number
                . '。未做静默截断，请改后重试');
        }
        // 只带 pinned / weight 两个字段：content.update 逐字段裁决，不夹带正文
        // 就不会让「只能改标题」的字段级 ACL 连累这次操作。
        $registry->execute('content.update', $base + [
            'pinned' => $op === 'pin:on' ? 1 : 0, 'weight' => $number,
        ]);
    }

    private static function acl(Kernel $kernel): ?AccessControlInterface
    {
        $c = $kernel->container();
        if (!$c->has(AccessControlInterface::class)) return null;
        $acl = $c->resolve(AccessControlInterface::class);
        return $acl instanceof AccessControlInterface ? $acl : null;
    }

    /** @return array{ok: false, status: int, code: int, message: string} */
    private static function fail(int $status, int $code, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'code' => $code, 'message' => $message];
    }
}
