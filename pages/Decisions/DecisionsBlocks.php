<?php
declare(strict_types=1);
namespace Wx\Pages\Decisions;

/**
 * 决策轨迹区块 —— 纯函数，无 IO。
 *
 * 三个状态必须长得不一样：允许、拒绝、不表态。「不表态」不是拒绝：
 * 它表示没有任何角色或 ACL 命中这个动作，通常是权限点名字写错，
 * 或这个动作根本不在清单里。混成一种颜色，排查就失去入口。
 */
final class DecisionsBlocks
{
    /**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    public static function build(array $data, string $notice): array
    {
        $blocks = [];
        if ($notice !== '') {
            $blocks[] = ['type' => 'notice', 'id' => 'decisions-notice', 'tone' => 'ok',
                'state' => 'done', 'title' => '提示', 'value' => $notice];
        }
        $blocks[] = self::summary($data);
        $blocks[] = self::form();
        if ((bool) ($data['asked'] ?? false)) $blocks[] = self::verdict($data);
        $blocks[] = self::chain($data);
        $blocks[] = self::boundary();
        return $blocks;
    }

    /** @return array<string, mixed> */
    private static function form(): array
    {
        return [
            'type' => 'form', 'id' => 'decisions-check',
            'title' => '现场裁决',
            'body' => '用当前登录身份问一次「这个动作能不能做」。不写库，不改授权。'
                . '资源为空表示问「这个动作本身」。',
            'action' => '/console/decisions', 'method' => 'get',
            'fields' => [
                ['tag' => 'input', 'id' => 'd-action', 'name' => 'action', 'type' => 'text',
                 'placeholder' => '动作，如 content.publish'],
                ['tag' => 'input', 'id' => 'd-type', 'name' => 'type', 'type' => 'text',
                 'placeholder' => '资源类型，如 post（可空）'],
                ['tag' => 'input', 'id' => 'd-id', 'name' => 'id', 'type' => 'text',
                 'placeholder' => '资源 id，如 42（可空）'],
                ['tag' => 'input', 'id' => 'd-field', 'name' => 'field', 'type' => 'text',
                 'placeholder' => '字段名，如 body（可空）'],
                ['tag' => 'submit', 'value' => '查询', 'label' => '裁决'],
            ],
            'note' => '裁决结果只代表「此刻、这个身份」，不代替对别人的授权判断。',
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function verdict(array $data): array
    {
        $check = $data['check'] ?? null;
        if (!is_array($check)) {
            return ['type' => 'notice', 'id' => 'decisions-verdict', 'tone' => 'warn',
                'state' => 'empty', 'title' => '没有动作可裁决',
                'value' => '请填写动作再提交。「空动作」不是一个合法的问题。'];
        }
        $action  = (string) ($check['action'] ?? '');
        $allowed = (bool) ($check['allowed'] ?? false);
        $decision = (string) ($check['decision'] ?? '');
        $reason = self::reasonOf($data, $action);

        [$tone, $state, $title, $hint] = match ($decision) {
            'allow'  => ['ok', 'ok', '允许', '有角色或 ACL 明确允许这个动作。'],
            'deny'   => ['warn', 'denied', '拒绝', '有更具体的拒绝压过了允许（显式 deny 或策略钩子收紧）。'],
            default  => ['info', 'empty', '不表态',
                '没有任何角色或 ACL 命中它。常见原因：动作名写错，或这个动作不在权限清单里。'],
        };
        $value = $action . ' → ' . $title;
        if ($reason !== '') $value .= '（来源：' . $reason . '）';
        $value .= '。' . $hint;
        if ($allowed && $decision !== 'allow') {
            // 理论上不会发生；真发生了说明 decide 与 allows 不一致，必须显式暴露。
            $value .= ' 注意：allowed 与 decision 不一致（' . ($allowed ? 'true' : 'false') . '）。';
        }
        return ['type' => 'notice', 'id' => 'decisions-verdict', 'tone' => $tone,
            'state' => $state, 'title' => '裁决结果：' . $title, 'value' => $value];
    }

    /** 从本次请求的决策链里取最后一条同名动作的 reason。 */
    private static function reasonOf(array $data, string $action): string
    {
        $entries = array_reverse((array) ($data['entries'] ?? []));
        foreach ($entries as $entry) {
            if (!is_array($entry)) continue;
            if ((string) ($entry['action'] ?? '') !== $action) continue;
            $reason = (string) ($entry['reason'] ?? '');
            return $reason === 'policy_tightened' ? '策略钩子收紧' : ($reason === 'base' ? '基础判定' : $reason);
        }
        return '';
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function chain(array $data): array
    {
        $entries = (array) ($data['entries'] ?? []);
        $rows = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) continue;
            $rows[] = [
                'k'    => (string) ($entry['action'] ?? ''),
                'v'    => self::label((string) ($entry['decision'] ?? '')),
                'ok'   => (string) ($entry['decision'] ?? '') === 'allow',
                'note' => (string) ($entry['actor'] ?? '') . ' · '
                    . (string) ($entry['resource'] ?? '(无资源)') . ' · '
                    . (string) ($entry['at'] ?? ''),
            ];
        }
        return ['type' => 'table', 'id' => 'decisions-chain',
            'title' => '本次请求的决策链（' . count($rows) . ' 条）', 'rows' => $rows];
    }

    private static function label(string $decision): string
    {
        return match ($decision) {
            'allow' => '允许',
            'deny'  => '拒绝',
            'abstain' => '不表态',
            default => $decision,
        };
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function summary(array $data): array
    {
        if ((string) ($data['error'] ?? '') !== '') {
            return ['type' => 'notice', 'id' => 'decisions-summary', 'tone' => 'warn',
                'state' => 'unavailable', 'title' => '决策轨迹不可用',
                'value' => (string) $data['error']];
        }
        $cache = (array) ($data['cache'] ?? []);
        $hit = (int) ($cache['hits'] ?? 0);
        $miss = (int) ($cache['misses'] ?? 0);
        $total = $hit + $miss;
        $rate = $total === 0 ? '尚无查询' : (string) (int) round($hit * 100 / $total) . '%';
        return ['type' => 'notice', 'id' => 'decisions-summary', 'tone' => 'info', 'state' => 'ok',
            'title' => '本次请求',
            'value' => '决策 ' . count((array) ($data['entries'] ?? [])) . ' 条 · 权限缓存命中 '
                . $rate . '（' . $hit . ' 中 / ' . $miss . ' 未中，'
                . ((int) ($cache['enabled'] ?? 0) === 1 ? '启用' : '停用') . '）· 后端 '
                . (string) (((array) ($cache['backend'] ?? []))['driver'] ?? 'memory') . '。'];
    }

    /** @return array<string, mixed> */
    private static function boundary(): array
    {
        return ['type' => 'notice', 'id' => 'decisions-boundary', 'tone' => 'info',
            'state' => 'ok', 'title' => '这不是历史',
            'value' => 'permission.decisions 返回当前进程内最近 100 条。PHP-FPM 下一个请求一个进程，'
                . '所以它通常只包含你这一次请求的判定；常驻进程（测试、CLI）下会跨请求累积。'
                . '它证明的是「这一次请求经过了哪些判定」，不是「站点过去发生过什么」——'
                . '历史留痕写在 permission_audit 表里，本页没有查询入口。'];
    }
}
