<?php
declare(strict_types=1);
namespace Wx\Pages\Attachments;

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Pages\Shared\ApiFailure;

/**
 * 附件库的写入口 —— 上传 / 改名 / 改可见性 / 改挂载 / 删除。
 *
 * 页面先问一次 attachment.manage（上传问 attachment.upload），执行者内部还会再判一次
 * （含上传者 own 权限）；页面直调执行者绕过 API 分发器，所以这一层不能省。
 *
 * 三类「接口不管、页面必须管」的事：正文引用（软删后链接会 404）、
 * 改挂到不存在的文章（没有 list-all 接口能补救）、展示名超长被静默截断。
 * 具体校验与文案在 AttachmentsInput，正文 / 目标的读取在这里。
 *
 * 上传是唯一不走 locate / 引用扫描的分支：新附件还没有行可查，
 * 类型与大小校验全部在 AttachmentGuard（白名单 + 魔数 + 上限），
 * 页面只负责把表单文件字节原样交给执行器，绝不自行改名或截断。
 */
final class AttachmentsAction
{
    public const OPS = ['rename', 'public', 'private', 'move', 'delete', 'upload:public', 'upload:private'];

    /** 上传动作 → 接口 visibility 参数；其余动作无此映射。 */
    private const UPLOAD_VISIBILITY = ['upload:public' => 'public', 'upload:private' => 'private'];

    /** @param array<string, mixed>|null $identity
     *  @return array<string, mixed> */
    public static function run(Kernel $kernel, Request $request, ?array $identity): array
    {
        $body = $request->body();
        $op   = trim((string) ($body['op'] ?? ''));
        $id   = (int) ($body['id'] ?? 0);
        $slug = trim((string) ($body['slug'] ?? ''));
        if (!in_array($op, self::OPS, true)) {
            return self::fail(422, 42201, '动作只能由表单按钮给出（上传 / 改名 / 可见性 / 改挂载 / 删除）。');
        }
        if ($identity === null) return self::fail(401, 40101, '身份未建立，请刷新页面后重试。');

        $container = $kernel->container();
        $acl = $container->has(AccessControlInterface::class)
            ? $container->resolve(AccessControlInterface::class) : null;
        $point = isset(self::UPLOAD_VISIBILITY[$op]) ? 'attachment.upload' : 'attachment.manage';
        if ($acl === null || !$acl instanceof AccessControlInterface
            || !$acl->allows($identity, $point)) {
            return self::fail(403, 40303, '缺少 ' . $point . ' 权限点，写操作被拒绝（fail-closed）。');
        }
        if (!$container->has(ApiRegistryInterface::class)) {
            return self::fail(503, 50301, '接口清单未装配，无法提交。');
        }
        $registry = $container->resolve(ApiRegistryInterface::class);

        if (isset(self::UPLOAD_VISIBILITY[$op])) {
            return self::upload($registry, $request, $identity, $slug,
                self::UPLOAD_VISIBILITY[$op]);
        }
        if ($id <= 0 || preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/', $slug) !== 1) {
            return self::fail(422, 42201, '缺少合法的附件 id 或文章 slug。');
        }

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
     * 上传：表单文件字节 → 执行器（类型 / 魔数 / 大小校验都在 AttachmentGuard）。
     *
     * 文件字段缺失或 PHP 上传报错时明确 422，绝不把空字节递给执行器
     * 去换一句「文件内容为空」——那会把「没选文件」和「选了空文件」混成一条。
     * 展示名直接用客户端文件名（执行器会净化并按扩展名推导 MIME），
     * 页面不提供「另起名」输入：接口的 name 参数同时决定扩展名，改了会破坏类型判定。
     *
     * @param array<string, mixed>|null $identity
     * @return array<string, mixed>
     */
    private static function upload(ApiRegistryInterface $registry, Request $request,
        ?array $identity, string $slug, string $visibility): array
    {
        if ($slug !== '' && preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/', $slug) !== 1) {
            return self::fail(422, 42201, '挂载的文章 slug 格式不合法。');
        }
        $file = $request->file('file');
        if ($file === null) {
            return self::fail(422, 42201, '没有收到文件：请选择要上传的文件后重试'
                . '（文件内容无法在失败后保留，需要重新选择）。');
        }
        try {
            $out = $registry->execute('attachments.upload', [
                'name'       => $file['name'] !== '' ? $file['name'] : 'unnamed',
                'post_slug'  => $slug,
                'visibility' => $visibility,
                '_content'   => $file['bytes'],
                '_identity'  => $identity,
            ]);
        } catch (\Throwable $e) {
            return ApiFailure::describe($e);
        }
        $newId = (int) ($out['id'] ?? 0);
        return ['ok' => true, 'status' => 200, 'code' => 0, 'message' => 'ok',
            'op' => 'upload', 'id' => $newId, 'outcome' => 'upload',
            'changed' => true,
            'detail' => '已上传 #' . $newId . ' · ' . (string) ($out['name'] ?? '')
                . ' · ' . number_format((int) ($out['bytes'] ?? 0) / 1024, 1) . ' KB'
                . ' · ' . (string) ($out['mime'] ?? '')
                . ($slug !== '' ? ' · 已挂载到「' . $slug . '」' : ' · 未挂载到文章'),
            'hint' => '同名不同内容的文件不会互相覆盖：存储路径由内容哈希决定。'];
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
