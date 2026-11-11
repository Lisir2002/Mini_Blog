<?php
declare(strict_types=1);
namespace Wx\Pages\Attachments;

/**
 * 附件写操作的输入校验与结果文案 —— 纯函数，无 IO。
 *
 * 单独成文件的原因：五个动作各自的「接口不报错、但语义已经错了」的场景
 * 都要在这里解释清楚，篇幅比分发逻辑还长。与 MemberInput 同一套做法：
 * 校验归校验、文案归文案，Action 只负责权限与执行。
 *
 * `$targetCheck` 由 Action 预先查好：null = 本次不是改挂载；'' = 目标文章存在；
 * 非空 = 读不到目标（含原因）。目标文章的存在性必须查，因为接口不查，
 * 而系统没有「列出全站附件」的接口可以补救挂错的记录。
 */
final class AttachmentsInput
{
    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $row
     * @param array<string, mixed> $ref
     * @return array{ok: true, in: array<string, mixed>, operation: string, detail: string, hint: string}
     *          |array{ok: false, message: string}
     */
    public static function normalize(string $op, array $body, array $row, array $ref,
        string $slug, int $id, ?string $targetCheck): array
    {
        $in = ['id' => $id];
        $old = (string) ($row['name'] ?? '');
        $url = '/attach/download?id=' . $id;

        if ($op === 'rename') {
            $new = trim((string) ($body['new_name'] ?? ''));
            if ($new === '') return self::no('请填写新的展示名。');
            $len = self::length($new);
            if ($len > 120) {
                return self::no('展示名最多 120 个字符（当前 ' . $len
                    . ' 个）。接口会静默截断，这里先拒绝。');
            }
            if (($ref['by_name'] ?? false) === true && trim((string) ($body['confirm'] ?? '')) !== $new) {
                return self::no('正文里按展示名引用了「' . $old . '」。改完这些引用就对不上了 —— '
                    . '若要继续，请在「确认新名」里原样再写一次 ' . $new . '；更稳妥的做法是先改正文。');
            }
            $in['name'] = $new;
            $detail = '#' . $id . ' 展示名从「' . $old . '」改为「' . $new . '」。'
                . '字节与存储路径不变，下载 URL 仍是 ' . $url . '。';
            $hint = (($ref['by_name'] ?? false) === true)
                ? '注意：正文里仍写着旧名「' . $old . '」—— 按名字引用会断，按 URL 引用不受影响。' : '';
            return self::yes($in, 'attachments.update', $detail, $hint);
        }

        if ($op === 'public' || $op === 'private') {
            $in['visibility'] = $op;
            return self::yes($in, 'attachments.update',
                '#' . $id . ' 可见性改为 ' . $op . '。改可见性不产生副本，也不让旧链接失效；'
                . ($op === 'private' ? '私有附件下载时每次二次鉴权，无 attachment.read 会被 403。'
                    : '公开附件可从文章页直接下载。'), '');
        }

        if ($op === 'move') {
            $target = trim((string) ($body['target_slug'] ?? ''));
            if ($target === '' || preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/', $target) !== 1) {
                return self::no('请填写合法的目标文章 slug。');
            }
            if ($targetCheck === null) return self::no('目标文章未校验，请重新提交。');
            if ($targetCheck !== '') {
                return self::no('目标文章「' . $target . '」读不到（' . $targetCheck
                    . '）。系统没有列出全站附件的接口，挂到不存在的 slug 会让这条附件再也找不回来。');
            }
            $in['post_slug'] = $target;
            $detail = '#' . $id . ' 挂载从「' . $slug . '」改到「' . $target . '」。'
                . '下载 URL 按 id 不变，但这篇文章的附件列表里不再列出它。';
            $hint = (($ref['by_url'] ?? false) === true || ($ref['by_name'] ?? false) === true)
                ? '注意：正文仍引用这条附件（' . (string) ($ref['label'] ?? '')
                    . '）。链接还能用，但它已经不属于这篇文章的附件清单了。' : '';
            return self::yes($in, 'attachments.update', $detail, $hint);
        }

        if (trim((string) ($body['confirm'] ?? '')) !== (string) $id) {
            return self::no('删除需要确认：请在「确认 id」里原样手写 ' . $id . '。');
        }
        if (($ref['by_url'] ?? false) === true || ($ref['by_name'] ?? false) === true) {
            return self::no('正文还引用着这条附件（' . (string) ($ref['label'] ?? '')
                . '）。软删之后下载会 404、列表里也会消失 —— 请先把文章里的引用改掉再删。');
        }
        return self::yes($in, 'attachments.delete',
            '#' . $id . ' 已软删除。列表按 deleted_at 过滤，之后在这篇文章里看不到它；'
            . '字节仍在磁盘，等引用计数为 0 才由独立流程回收。', '');
    }

    /** mbstring 未必装得上：用 preg_split 保持字符数口径，与系统层 displayName 一致。 */
    private static function length(string $value): int
    {
        if (function_exists('mb_strlen')) return mb_strlen($value, 'UTF-8');
        return count(preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /** @param array<string, mixed> $in
     *  @return array{ok: true, in: array<string, mixed>, operation: string, detail: string, hint: string} */
    private static function yes(array $in, string $operation, string $detail, string $hint): array
    {
        return ['ok' => true, 'in' => $in, 'operation' => $operation,
            'detail' => $detail, 'hint' => $hint];
    }

    /** @return array{ok: false, message: string} */
    private static function no(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }
}
