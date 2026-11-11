<?php
declare(strict_types=1);
namespace Wx\Pages\Write;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Pages\Shared\ApiFailure;

/**
 * 写文章的写入口 —— 新建走 content.create，已有文章走 content.update。
 *
 * 两条硬规则：
 *  1. 状态只能由按钮给出（draft / published），缺失或未知一律 422 ——
 *     不能默认成「发布」，也不能把空值悄悄当成草稿；
 *  2. 新建时作者取自当前身份，不由表单填写 —— 允许手填作者等于允许冒名。
 */
final class WriteAction
{
    public const STATUSES = ['draft', 'published'];
    private const MAX_TITLE = 200;

    /** @return array{ok: bool, status: int, code: int, message: string, slug?: string} */
    public static function run(Kernel $kernel, Request $request, ?array $identity): array
    {
        $body   = $request->body();
        $slug   = trim((string) ($body['slug'] ?? ''));
        $title  = trim((string) ($body['title'] ?? ''));
        $text   = (string) ($body['body'] ?? '');
        $status = trim((string) ($body['status'] ?? ''));
        // 新建还是编辑不能靠「slug 是否为空」推断：新建时也允许自定义 slug。
        $mode   = trim((string) ($body['mode'] ?? 'create'));

        if ($identity === null) {
            return self::fail(401, 40101, '身份未建立：表单票据未通过校验，请刷新页面后重试');
        }
        if ($title === '') return self::fail(422, 42201, '标题不能为空');
        if (self::length($title) > self::MAX_TITLE) {
            return self::fail(422, 42201, '标题最多 ' . self::MAX_TITLE . ' 个字符，当前 '
                . self::length($title) . ' 个');
        }
        if (!in_array($mode, ['create', 'edit'], true)) {
            return self::fail(422, 42201, 'mode 只能是 create / edit，当前为「' . $mode . '」');
        }
        if ($mode === 'edit' && $slug === '') return self::fail(422, 42201, '编辑模式缺少 slug');
        if (!in_array($status, self::STATUSES, true)) {
            return self::fail(422, 42201, '状态只能是 draft / published，当前为「'
                . ($status === '' ? '(空)' : $status) . '」');
        }

        $container = $kernel->container();
        if (!$container->has(ApiRegistryInterface::class)) {
            return ['ok' => false, 'status' => 503, 'code' => 50301,
                'message' => '接口清单未装配，无法保存。'];
        }
        $registry = $container->resolve(ApiRegistryInterface::class);

        try {
            if ($mode === 'create') {
                return self::create($registry, $slug, $title, $text, $status, $identity);
            }
            $registry->execute('content.update', [
                'slug' => $slug, 'title' => $title, 'body' => $text, 'status' => $status,
                '_identity' => $identity,
            ]);
        } catch (\Throwable $e) {
            return ApiFailure::describe($e);
        }
        return ['ok' => true, 'status' => 200, 'code' => 0, 'message' => 'ok', 'slug' => $slug, 'mode' => $mode];
    }

    /** @param array<string, mixed> $identity
     *  @return array{ok: bool, status: int, code: int, message: string, slug?: string} */
    private static function create(ApiRegistryInterface $registry, string $slug, string $title,
        string $text, string $status, array $identity): array
    {
        $in = [
            'title' => $title, 'body' => $text, 'status' => $status,
            'author' => (string) ($identity['username'] ?? ''),
        ];
        if ($slug !== '') $in['slug'] = $slug;
        $created = $registry->execute('content.create', $in);
        return ['ok' => true, 'status' => 200, 'code' => 0, 'message' => 'ok',
            'slug' => (string) ($created['slug'] ?? ''), 'mode' => 'create'];
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    /** @return array{ok: false, status: int, code: int, message: string} */
    private static function fail(int $status, int $code, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'code' => $code, 'message' => $message];
    }
}
