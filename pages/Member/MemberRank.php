<?php
declare(strict_types=1);
namespace Wx\Pages\Member;

/**
 * 排行榜与单人档案 —— 两个「数据类」区块。
 *
 * 排行榜最容易犯的错是口径：榜单只包含**有成长档案**的人（拿过经验的人），
 * 所以空榜不等于站点没有成员，也不等于没人注册。页面必须把这三件事分开说，
 * 并且给出「这里没有、去哪里看」的出口。
 */
final class MemberRank
{
    /**
     * @param array<string, mixed>|null $payload
     * @return list<array<string, mixed>>
     */
    public static function board(?array $payload, int $offset, string $error): array
    {
        if ($payload === null) {
            return [['type' => 'notice', 'id' => 'member-board-error', 'tone' => 'warn',
                'state' => 'unavailable', 'title' => '排行榜不可用',
                'value' => $error === '' ? '接口没有返回数据，也没有给出原因。' : $error]];
        }
        $items = [];
        foreach ((array) ($payload['items'] ?? []) as $row) {
            if (is_array($row)) $items[] = $row;
        }
        $total = (int) ($payload['total'] ?? 0);
        if ($items === [] && $total > 0) {
            return [['type' => 'notice', 'id' => 'member-board-beyond', 'tone' => 'warn',
                'state' => 'beyond', 'title' => '第 ' . ($offset + 1) . ' 位之后没有内容',
                'value' => '榜单只有 ' . $total . ' 位有档案的成员，offset=' . $offset . ' 已经越过末尾。'
                    . '这不是「没有排行」—— 前面还有 ' . $total . ' 位，见 /console/member?offset=0。']];
        }
        if ($total === 0) {
            return [['type' => 'notice', 'id' => 'member-board-empty', 'tone' => 'info',
                'state' => 'empty', 'title' => '还没有人获得经验',
                'value' => '排行榜只包含有成长档案的成员：从没拿过经验的注册用户不会出现在这里。'
                    . '所以这不是「站点没有成员」，也不是「用户列表是空的」—— 账号在 /console/users，'
                    . '这里空只说明还没有任何经验被发出（规则停用、事件没触发、或站点刚装好）。']];
        }

        $rows = [];
        foreach ($items as $row) {
            $orphan = (bool) ($row['orphan'] ?? false);
            $name = (string) ($row['display_name'] ?? '');
            if ($name === '') $name = (string) ($row['username'] ?? '');
            if ($name === '') $name = '账号已删的 #' . (string) ($row['user_id'] ?? '');
            $rows[] = ['k' => '#' . (int) ($row['rank'] ?? 0) . ' ' . $name,
                'v' => (int) ($row['xp'] ?? 0) . ' 经验', 'ok' => !$orphan,
                'note' => 'Lv.' . (int) ($row['level'] ?? 0) . ' ' . (string) ($row['level_name'] ?? '')
                    . ($orphan ? ' · 账号已删除，档案还在' : '')];
        }
        $returned = (int) ($payload['returned'] ?? count($rows));
        $more = (bool) ($payload['has_more'] ?? false);
        $blocks = [['type' => 'notice', 'id' => 'member-board-window',
            'tone' => $more ? 'warn' : 'ok', 'state' => $more ? 'truncated' : 'ok',
            'title' => '第 ' . ($offset + 1) . '–' . ($offset + $returned) . ' 位 · 共 ' . $total . ' 位有档案的成员',
            'value' => $more
                ? '后面还有 ' . max(0, $total - $offset - $returned) . ' 位没有翻到。'
                    . '注意 offset 分页在榜单变动时会漏行或重行 —— 有人加分，后面的名次就整体前移。'
                : '这已是最后一页。']];
        $blocks[] = ['type' => 'table', 'id' => 'member-board',
            'title' => '经验排行 · 本页 ' . $returned . ' 位', 'rows' => $rows];
        $blocks[] = self::pages($offset, $returned, $more, (int) ($payload['next_offset'] ?? 0));
        return $blocks;
    }

    /** @return array<string, mixed> */
    private static function pages(int $offset, int $returned, bool $more, int $next): array
    {
        $limit = MemberQuery::LIMIT;
        $items = [];
        if ($offset > 0) {
            $items[] = ['label' => '上一页', 'href' => '/console/member?offset=' . max(0, $offset - $limit)];
        }
        $items[] = ['label' => '下一位起（第 ' . ($offset + 1) . ' 位）',
            'href' => '/console/member?offset=' . $offset, 'active' => true];
        if ($more) {
            $items[] = ['label' => '下一页', 'href' => '/console/member?offset=' . $next];
        }
        return ['type' => 'links', 'id' => 'member-board-pages', 'title' => '翻页', 'items' => $items];
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return list<array<string, mixed>>
     */
    public static function profile(?array $payload, string $state, string $user, string $error): array
    {
        $blocks = [['type' => 'form', 'id' => 'member-lookup', 'title' => '查一个人的成长',
            'action' => '/console/member', 'method' => 'get',
            'body' => '填用户 ID 或用户名。查不到会明确说「用户不存在」，不会给你一份 0 经验 / Lv.1 的空档案。',
            'fields' => [
                ['tag' => 'input', 'id' => 'member-user', 'name' => 'user', 'type' => 'text',
                 'value' => $user, 'placeholder' => '用户 ID 或用户名'],
                ['tag' => 'submit', 'label' => '查看'],
            ],
            'note' => '翻页参数会在查询时丢失（一次只看一个人）：本页不做「筛完再翻页」的组合查询。']];

        if ($state === 'idle') {
            $blocks[] = ['type' => 'notice', 'id' => 'member-lookup-idle', 'tone' => 'info',
                'state' => 'idle', 'title' => '还没有查谁',
                'value' => '上面填一个用户即可；也可以直接访问 /console/member?user=1。'];
            return $blocks;
        }
        if ($state === 'missing') {
            $blocks[] = ['type' => 'notice', 'id' => 'member-lookup-missing', 'tone' => 'warn',
                'state' => 'missing', 'title' => '用户不存在：' . $user,
                'value' => '接口返回 40403。这里刻意不回退成「0 经验 / Lv.1」的假档案 —— '
                    . '一份看起来正常的空档案会让人以为这个人真的存在，进而给他发经验、授头衔。'];
            return $blocks;
        }
        if ($payload === null) {
            $blocks[] = ['type' => 'notice', 'id' => 'member-lookup-failed', 'tone' => 'warn',
                'state' => 'unavailable', 'title' => '查不了：' . $user,
                'value' => $error === '' ? '接口没有返回数据，也没有给出原因。' : $error];
            return $blocks;
        }

        if ((bool) ($payload['orphan'] ?? false)) {
            $blocks[] = ['type' => 'notice', 'id' => 'member-lookup-orphan', 'tone' => 'warn',
                'state' => 'orphan', 'title' => '档案在，但查不到账号',
                'value' => '账号可能已被删除；也可能是插件直接按 user_id 发过经验（发经验不校验账号是否存在）。'
                    . '这条记录仍会出现在排行榜上，名次后面的「账号已删除，档案还在」说的就是它。'];
        }
        $blocks[] = ['type' => 'table', 'id' => 'member-lookup-result',
            'title' => '档案 · ' . (string) ($payload['display_name'] ?? '') . '（' . (string) ($payload['user_id'] ?? '') . '）',
            'rows' => self::profileRows($payload)];
        return $blocks;
    }

    /**
     * @param array<string, mixed> $p
     * @return list<array<string, mixed>>
     */
    private static function profileRows(array $p): array
    {
        $titles = [];
        foreach ((array) ($p['titles'] ?? []) as $t) {
            if (!is_array($t)) continue;
            $titles[] = (string) ($t['name'] ?? '') . '（' . ((string) ($t['source'] ?? '') === 'level' ? '等级' : '手动') . '）';
        }
        $next = $p['next_level'] ?? null;
        return [
            ['k' => '用户名', 'v' => (string) ($p['username'] ?? ''), 'ok' => true, 'note' => ''],
            ['k' => '经验', 'v' => (int) ($p['xp'] ?? 0), 'ok' => true, 'note' => '全部来自 append-only 流水'],
            ['k' => '等级', 'v' => 'Lv.' . (int) ($p['level'] ?? 0) . ' ' . (string) ($p['level_name'] ?? ''),
                'ok' => true, 'note' => (string) ($p['level_color'] ?? '')],
            ['k' => '距下一级', 'v' => $next === null ? '已满级' : (int) ($p['xp_to_next'] ?? 0) . ' 经验',
                'ok' => true, 'note' => $next === null ? '' : '下一级 Lv.' . (int) $next],
            ['k' => '本级进度', 'v' => (int) ($p['progress'] ?? 0) . '%', 'ok' => true, 'note' => '满级固定 100%'],
            ['k' => '头衔', 'v' => $titles === [] ? '（无）' : implode('、', $titles), 'ok' => true,
                'note' => '头衔不影响任何权限决策'],
        ];
    }
}
