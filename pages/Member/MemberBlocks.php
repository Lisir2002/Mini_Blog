<?php
declare(strict_types=1);
namespace Wx\Pages\Member;

/**
 * 成员成长页的区块编排 —— 纯函数，无 IO。
 *
 * 每块独立成败：规则读不到不该让等级表跟着消失，榜单空不该让档案查询也消失。
 * 页面底部固定两条：出口（去用户 / 角色 / 内容）与边界（只读、榜单口径、分页口径）。
 */
final class MemberBlocks
{
    /**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    public static function build(array $data): array
    {
        if (($data['ok'] ?? false) !== true) {
            $status = (int) ($data['status'] ?? 503);
            return [[
                'type' => 'notice', 'id' => 'member-error', 'tone' => 'warn',
                'state' => $status === 422 ? 'bad-param' : 'unavailable',
                'title' => $status === 422 ? '查询条件不合法' : '成长数据不可用',
                'value' => (string) ($data['error'] ?? '未知原因'),
            ], self::boundary()];
        }

        $fail = (array) ($data['fail'] ?? []);
        $can  = (array) ($data['can'] ?? []);
        $blocks = array_merge([self::header()], MemberNotice::build($data), MemberForms::build($data));
        $blocks = array_merge($blocks, MemberPanels::levels(
            self::payload($data, 'levels'), (string) ($fail['levels'] ?? '')));
        $blocks = array_merge($blocks, MemberPanels::titles(
            self::payload($data, 'titles'), (string) ($fail['titles'] ?? '')));
        $blocks = array_merge($blocks, MemberPanels::rules(
            self::payload($data, 'rules'), (bool) ($can['rule'] ?? false), (string) ($fail['rules'] ?? '')));
        $blocks = array_merge($blocks, MemberRank::board(
            self::payload($data, 'board'), (int) ($data['offset'] ?? 0), (string) ($fail['board'] ?? '')));
        $blocks = array_merge($blocks, MemberRank::profile(
            self::payload($data, 'profile'), (string) ($data['profile_state'] ?? 'idle'),
            (string) ($data['user'] ?? ''), (string) ($data['profile_error'] ?? '')));
        $blocks[] = self::nav();
        $blocks[] = self::boundary();
        return $blocks;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private static function payload(array $data, string $key): ?array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : null;
    }

    /** @return array<string, mixed> */
    private static function header(): array
    {
        return ['type' => 'notice', 'id' => 'member-scope', 'tone' => 'info', 'state' => 'read-write',
            'title' => '成长数据：上面看现状，下面做改动',
            'value' => '等级表、头衔目录、经验规则、排行榜与单人档案来自 member.* 只读接口；'
                . '发经验、定规则、定头衔、授头衔、定等级走下面的操作台，一次只显示一张表单。'
                . '能看这一页（member.read）不等于能改：每个动作各自要自己的写权限点，'
                . '提交时现场裁决，票据只证明「谁从哪一页交的」。'];
    }

    /** @return array<string, mixed> */
    private static function nav(): array
    {
        return ['type' => 'links', 'id' => 'member-nav', 'title' => '相关页面', 'items' => [
            ['label' => '用户管理', 'href' => '/console/users', 'note' => '账号在这里，成长在左边'],
            ['label' => '角色授权', 'href' => '/console/roles', 'note' => 'member.read 属于哪个角色'],
            ['label' => '决策轨迹', 'href' => '/console/decisions', 'note' => '为什么这次被允许'],
            ['label' => '内容管理', 'href' => '/console/content', 'note' => '发文章是主要加分动作'],
        ]];
    }

    /** @return array<string, mixed> */
    private static function boundary(): array
    {
        return ['type' => 'notice', 'id' => 'member-boundary', 'tone' => 'info', 'state' => 'boundary',
            'title' => '这一页的边界',
            'value' => '榜单只含有成长档案的人（拿过经验的人），空榜不等于没有成员；'
                . 'total 是有档案的成员总数，returned 才是本页条数。分页用 offset，'
                . '榜单变动时会漏行或重行 —— 要严格快照得按经验游标翻页，那是另一版。'
                . '写操作不做批量、不回溯已入账经验、不展示经验流水（接口未开）；'
                . '删除定义只改解释规则，不清理已落库的授予关系。'
                . '等级变更不回溯通知、头衔不参与权限，都是刻意设计。'];
    }
}
