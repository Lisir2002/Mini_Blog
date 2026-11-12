<?php
declare(strict_types=1);
namespace Wx\Pages\Terms;

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Pages\Shared\ApiFailure;

/**
 * 分类与标签的写入口 —— 页面层再问一次写权限。
 *
 * 两个写接口的权限点**不同**：术语增删改要 content.manage_terms，文章挂载要
 * content.edit。页面声明的是 content.read（能看），所以这里按动作现场裁决；
 * 页面直调执行者会绕过 API 分发器，权限层缺席即 403。
 *
 * 预查还承担一件事：接口不校验「引用 / ID 是否真的存在」，写错时分别返回
 * 40404 / 40404。页面先把清单读全（不受展示用的 60 条窗口限制），把「写错但
 * 不报错」的输入挡在写库之前，并给出具体是哪一个引用拼错了。
 */
final class TermsAction
{
    /** @param array<string, mixed>|null $identity
     *  @return array<string, mixed> */
    public static function run(Kernel $kernel, Request $request, ?array $identity): array
    {
        $body = $request->body();
        $op = trim((string) ($body['op'] ?? ''));
        if ($identity === null) return self::fail(401, 40101, '身份未建立，请刷新页面后重试。');

        // 表单 action 带 ?slug=，但直接构造 POST 时可能只有 body 里有文章标识。
        // 统一在这里定一次，避免术语表单的 term_slug 被误当成文章 slug。
        $postSlug = trim((string) ($body['slug'] ?? $request->query()['slug'] ?? ''));
        if ($postSlug !== '') $body['slug'] = $postSlug;

        $permission = self::permissionFor($op);
        if ($permission !== null && !self::allows($kernel, $identity, $permission)) {
            return self::fail(403, 40303, '缺少 ' . $permission . ' 权限点，写操作被拒绝（fail-closed）。'
                . '能看分类不等于能改分类，也不等于能改文章的挂载。');
        }
        $container = $kernel->container();
        if (!$container->has(ApiRegistryInterface::class)) {
            return self::fail(503, 50301, '接口清单未装配，无法提交。');
        }
        $registry = $container->resolve(ApiRegistryInterface::class);
        $input = TermsInput::normalize($body, self::facts($registry, $body));
        if (($input['ok'] ?? false) !== true) {
            return self::fail(422, 42201, (string) ($input['message'] ?? '参数不合法。'));
        }

        try {
            $out = $registry->execute((string) $input['operation'],
                (array) $input['in'] + ['_identity' => $identity]);
        } catch (\Throwable $e) {
            return ApiFailure::describe($e);
        }
        return self::result((string) $input['op'], (string) $input['detail'],
            (string) $input['hint'], $out, $postSlug);
    }

    private static function permissionFor(string $op): ?string
    {
        if ($op === 'attach') return 'content.edit';
        if (str_starts_with($op, 'create_') || in_array($op, ['term_update', 'term_delete'], true)) {
            return 'content.manage_terms';
        }
        return null; // 未登记的动作交给 TermsInput 报 422，不误报 403
    }

    /** @param array<string, mixed>|null $identity */
    private static function allows(Kernel $kernel, ?array $identity, string $permission): bool
    {
        $c = $kernel->container();
        if (!$c->has(AccessControlInterface::class)) return false;
        $acl = $c->resolve(AccessControlInterface::class);
        return $acl instanceof AccessControlInterface && $acl->allows($identity, $permission);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed> terms_ok / post_ok / known
     */
    private static function facts(ApiRegistryInterface $registry, array $body): array
    {
        $facts = ['terms_ok' => false, 'post_ok' => false, 'known' => []];
        try {
            // 读全量，不套页面展示的 60 条窗口：第 61 个术语照样是合法引用。
            $items = (array) ($registry->execute('content.terms', ['kind' => 'any'])['items'] ?? []);
            $known = [];
            foreach ($items as $row) {
                if (!is_array($row)) continue;
                $key = (string) ($row['kind'] ?? '') . ':' . (string) ($row['slug'] ?? '');
                if ($key !== ':') $known[$key] = $row;
            }
            $facts['known'] = $known;
            $facts['terms_ok'] = true;
        } catch (\Throwable $e) {
            return $facts; // 清单读不到：所有存在性校验失败，正是 fail-closed
        }
        $slug = trim((string) ($body['slug'] ?? ''));
        if ($slug !== '') {
            try {
                $registry->execute('content.post', ['slug' => $slug]);
                $facts['post_ok'] = true;
            } catch (\Throwable $e) {
                $facts['post_ok'] = false;
            }
        }
        return $facts;
    }

    /** @param array<string, mixed> $out @return array<string, mixed> */
    private static function result(string $op, string $detail, string $hint, array $out, string $slug): array
    {
        $changed = true;
        if ($op === 'create_category' || $op === 'create_tag') {
            $detail .= ' 记录 ID ' . (int) ($out['id'] ?? 0) . '。';
        } elseif ($op === 'term_update') {
            $changed = (bool) ($out['updated'] ?? false);
            if (!$changed) $detail .= ' 接口返回 updated=false：名称 / 说明本来就是这个值，没有写入。';
        } elseif ($op === 'term_delete') {
            $detail .= ' 同时清理了 ' . (int) ($out['relations_removed'] ?? 0) . ' 条文章绑定关系。';
        } else {
            $added = (int) ($out['added'] ?? 0);
            $removed = (int) ($out['removed'] ?? 0);
            $changed = ($added + $removed) > 0;
            $detail .= ' 新增 ' . $added . ' 条、移除 ' . $removed . ' 条绑定。';
            if (!$changed) $detail .= ' 集合本来就是这样，没有写入（整体替换幂等）。';
        }
        return ['ok' => true, 'status' => 200, 'code' => 0, 'message' => 'ok',
            'op' => $op, 'outcome' => $changed ? 'changed' : 'no-change',
            'changed' => $changed, 'detail' => $detail, 'hint' => $hint, 'slug' => $slug];
    }

    /** @return array{ok: false, status: int, code: int, message: string} */
    private static function fail(int $status, int $code, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'code' => $code, 'message' => $message];
    }
}
