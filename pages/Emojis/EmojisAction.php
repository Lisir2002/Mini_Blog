<?php
declare(strict_types=1);
namespace Wx\Pages\Emojis;

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Systems\Api\ApiOperationException;
use Wx\Systems\Api\Envelope;

/**
 * 表情写操作 —— 登记与删除共用一个入口，权限裁决在页面层现场完成。
 *
 * 页面直调 execute() 会绕过 API 分发器，所以 Action 必须自己问 emoji.manage；
 * 守卫只证明「谁从哪一页提交」，票据不替代写权限裁决。
 *
 * 同一个 40403 在两个动作里含义不同：登记时是「引用的附件不存在」，
 * 删除时是「自定义表情不存在」。照搬接口原文会让人去错地方找原因。
 */
final class EmojisAction
{
    /** @param array<string, mixed>|null $identity @return array<string, mixed> */
    public static function run(Kernel $kernel, Request $request, ?array $identity): array
    {
        if ($identity === null) {
            return self::fail(401, 40101, '身份未建立：表单票据未通过校验，请刷新页面后重试');
        }
        $container = $kernel->container();
        $acl = $container->has(AccessControlInterface::class)
            ? $container->resolve(AccessControlInterface::class) : null;
        if ($acl === null || !$acl->allows($identity, 'emoji.manage')) {
            return self::fail(403, 40303,
                '登记 / 删除表情要求 emoji.manage：能看目录（content.read）不等于能改表情表。');
        }
        if (!$container->has(ApiRegistryInterface::class)) {
            return self::fail(503, 50301, '接口清单未装配，写操作不可用。');
        }
        $registry = $container->resolve(ApiRegistryInterface::class);

        $body = $request->body();
        $data = EmojisQuery::fetch($kernel, $identity, $request->query());
        if (($data['state'] ?? '') === 'failed') {
            // 目录读不到就无法核对短码占用，给一个能提交的表单等于让人去撞 40901。
            return self::fail(503, 50301, '表情目录读取失败，写入口暂时关闭：' . (string) ($data['fail'] ?? ''));
        }
        $input = EmojisInput::check($body, (array) ($data['codes'] ?? []),
            (array) ($data['custom_codes'] ?? []));
        if (($input['ok'] ?? false) !== true) {
            return self::fail(422, 42201, (string) ($input['message'] ?? '输入不合法'));
        }

        $op = (string) $input['op'];
        $operation = $op === 'register' ? 'emojis.register' : 'emojis.delete';
        try {
            $r = $registry->execute($operation, (array) $input['params'] + ['_identity' => $identity]);
        } catch (\Throwable $e) {
            return self::fromThrowable($e, $op);
        }
        $created = $op === 'register' ? (bool) ($r['created'] ?? false) : false;
        $deleted = $op === 'delete' ? (bool) ($r['deleted'] ?? false) : false;
        return self::ok($op, $op === 'register' ? 'created' : 'deleted',
            $created || $deleted, (string) $input['detail'], (string) $input['hint']);
    }

    /** @return array<string, mixed> */
    private static function fromThrowable(\Throwable $e, string $op): array
    {
        if (!($e instanceof ApiOperationException)) {
            return self::fail(503, 50301, '表情操作失败：' . $e->getMessage());
        }
        $status = Envelope::statusOf($e->apiCode);
        if ($status === 500 && $e->apiCode < 50000) $status = 422;
        $message = $e->getMessage();
        if ($e->apiCode === 40403) {
            $message = $op === 'register'
                ? '引用的附件不存在或已被删除：请核对附件 id，它必须是本站仍在的图片附件。'
                : '自定义表情不存在：可能已被别人删掉，请刷新目录再选。';
        } elseif ($e->apiCode === 40901) {
            $message = $op === 'register'
                ? '短码已被占用：可能刚被别人登记，请刷新目录换一个短码。'
                : '内置表情不可删除，目录里也不会给它删除按钮。';
        }
        return self::fail($status, $e->apiCode, $message);
    }

    /** @return array<string, mixed> */
    private static function ok(string $op, string $outcome, bool $changed,
        string $detail, string $hint): array
    {
        return ['ok' => true, 'status' => 200, 'code' => 0, 'message' => 'ok',
            'op' => $op, 'outcome' => $outcome, 'changed' => $changed,
            'detail' => $detail, 'hint' => $hint];
    }

    /** @return array<string, mixed> */
    private static function fail(int $status, int $code, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'code' => $code, 'message' => $message];
    }
}
