<?php
declare(strict_types=1);
namespace Wx\Pages\Attachments;

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Pages\Shared\ApiFailure;

/**
 * 附件库的写入口 —— 改名 / 改可见性 / 改挂载 / 删除。
 *
 * 页面先问一次 attachment.manage，执行者内部还会再判一次（含上传者 own 权限）；
 * 页面直调执行者绕过 API 分发器，所以这一层不能省。
 *
 * 三类「接口不管、页面必须管」的事：正文引用（软删后链接会 404）、
 * 改挂到不存在的文章（没有 list-all 接口能补救）、展示名超长被静默截断。
 * 具体校验与文案在 AttachmentsInput，正文 / 目标的读取在这里。
 */
final class AttachmentsAction
{
    public const OPS = ['rename', 'public', 'private', 'move', 'delete'];

    /** @param array<string, mixed>|null $identity
     *  @return array<string, mixed> */
    public static function run(Kernel $kernel, Request $request, ?array $identity): array
    {
        $body = $request->body();
        $op   = trim((string) ($body['op'] ?? ''));
        $id   = (int) ($body['id'] ?? 0);
        $slug = trim((string) ($body['slug'] ?? ''));
        if (!in_array($op, self::OPS, true)) {
            return self::fail(422, 42201, '动作只能由表单按钮给出（' . implode(' / ', self::OPS) . '）。');
        }
        if ($identity === null) return self::fail(401, 40101, '身份未建立，请刷新页面后重试。');
        if ($id <= 0 || preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/', $slug) !== 1) {
            return self::fail(422, 42201, '缺少合法的附件 id 或文章 slug。');
        }

        $container = $kernel->container();
        $acl = $container->has(AccessControlInterface::class)
            ? $container->resolve(AccessControlInterface::class) : null;
        if ($acl === null || !$acl instanceof AccessControlInterface
            || !$acl->allows($identity, 'attachment.manage')) {
            return self::fail(403, 40303, '缺少 attachment.manage 权限点，写操作被拒绝（fail-closed）。');
        }
        if (!$container->has(ApiRegistryInterface::class)) {
            return self::fail(503, 50301, '接口清单未装配，无法提交。');
        }
        $registry = $container->resolve(ApiRegistryInterface::class);

        $row = self::locate($registry, $slug, $id, $identity);
        if (!is_array($row)) return self::fail(422, 42201, $row);
        $error = '';
        $text = self::body($registry, $slug, $identity, $error);
        $refs = $error === '' ? AttachmentsRefs::scan($text, [$row]) : AttachmentsRefs::unknown([$row]);
        $ref = (array) ($refs[$id] ?? ['by_url' => false, 'by_name' => false, 'label' => '']);
        if (in_array($op, ['delete', 'rename'], true) && $error !== '') {
            return self::fail(503, 50301, '读不到这篇文章的正文（' . $error
                . '），无法确认附件是否还被引用。本次提交被拒绝（fail-closed）：'
                . '「查不到」不等于「没引用」。');
        }

        $targetCheck = $op === 'move'
            ? self::target($registry, trim((string) ($body['target_slug'] ?? '')), $identity) : null;
        $input = AttachmentsInput::normalize($op, $body, $row, $ref, $slug, $id, $targetCheck);
        if (($input['ok'] ?? false) !== true) {
            return self::fail(422, 42201, (string) ($input['message'] ?? '参数不合法。'));
        }

        try {
            $out = $registry->execute((string) $input['operation'],
                (array) $input['in'] + ['_identity' => $identity]);
        } catch (\Throwable $e) {
            return ApiFailure::describe($e);
        }
        if ($input['operation'] === 'attachments.delete' && ($out['already'] ?? false) === true) {
            return self::ok($op, $id, 'already', false, '#' . $id . ' 此前已删除，本次没有变化。', '');
        }
        if ($input['operation'] === 'attachments.update' && (array) ($out['updated'] ?? []) === []) {
            return self::fail(422, 42201, '接口没有更新任何字段，请检查提交的参数。');
        }
        return self::ok($op, $id, $op, true, (string) $input['detail'], (string) $input['hint']);
    }

    /**
     * @param array<string, mixed>|null $identity
     * @return array<string, mixed>|string 找不到时返回原因字符串
     */
    private static function locate(ApiRegistryInterface $registry, string $slug, int $id,
        ?array $identity): array|string
    {
        try {
            $r = $registry->execute('attachments.list', ['post_slug' => $slug, '_identity' => $identity]);
        } catch (\Throwable $e) {
            return '读不到这篇文章的附件清单：' . $e->getMessage();
        }
        foreach ((array) ($r['items'] ?? []) as $row) {
            if (is_array($row) && (int) ($row['id'] ?? 0) === $id) return $row;
        }
        return '附件 #' . $id . ' 不在「' . $slug . '」的可见清单里：可能已被删除、'
            . '被改挂到别的文章，或当前身份看不到它。请回到列表重新选择。';
    }

    /** @param array<string, mixed>|null $identity */
    private static function body(ApiRegistryInterface $registry, string $slug,
        ?array $identity, string &$error): string
    {
        $error = '';
        try {
            $post = $registry->execute('content.post', ['slug' => $slug, '_identity' => $identity]);
            return (string) ($post['body'] ?? '');
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            return '';
        }
    }

    /** @param array<string, mixed>|null $identity @return string '' 表示目标存在 */
    private static function target(ApiRegistryInterface $registry, string $slug, ?array $identity): string
    {
        $error = '';
        self::body($registry, $slug, $identity, $error);
        return $error;
    }

    /** @return array<string, mixed> */
    private static function ok(string $op, int $id, string $outcome, bool $changed,
        string $detail, string $hint): array
    {
        return ['ok' => true, 'status' => 200, 'code' => 0, 'message' => 'ok',
            'op' => $op, 'id' => $id, 'outcome' => $outcome, 'changed' => $changed,
            'detail' => $detail, 'hint' => $hint];
    }

    /** @return array{ok: false, status: int, code: int, message: string} */
    private static function fail(int $status, int $code, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'code' => $code, 'message' => $message];
    }
}
