<?php
declare(strict_types=1);
namespace Wx\Systems\Member;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Systems\Api\ApiOperationException;

/**
 * 成员系统的管理接口 —— 发经验、定规则、定头衔、定等级。
 *
 * 所有写操作都要求对应权限点（在接口清单里声明 permission，由请求守卫裁决），
 * 执行者只做参数校验与调用用例，不再自己判断“是不是管理员”。
 */
final class MemberAdminOperations
{
    public function __construct(
        private XpAwarder $awarder,
        private TitleBook $titles,
        private LevelStoreInterface $levels,
        private MemberProfile $profile
    ) {}

    public function role(): string { return 'member_admin_operations'; }

    public function register(ApiRegistryInterface $registry): void
    {
        $awarder = $this->awarder;
        $titles  = $this->titles;
        $levels  = $this->levels;
        $profile = $this->profile;

        $registry->registerOperation('member.xp.award', static function (array $in) use ($awarder, $profile): array {
            $identifier = trim((string) ($in['user_id'] ?? ''));
            if ($identifier === '') throw new ApiOperationException('user_id 必填', 42201);
            $user = $profile->resolve($identifier);
            if ($user === null && !ctype_digit($identifier)) {
                throw new ApiOperationException('用户不存在', 40403);
            }
            $userId = $user === null ? $identifier : (string) ($user['id'] ?? $identifier);
            $amount = isset($in['amount']) ? (int) $in['amount'] : null;
            $result = $awarder->award(
                $userId,
                (string) ($in['action'] ?? ''),
                (string) ($in['event_key'] ?? ''),
                (string) ($in['note'] ?? ''),
                $amount
            );
            return ['user_id' => $userId] + $result;
        });

        $registry->registerOperation('member.xp.rules', static function (array $in) use ($levels): array {
            $rows = array_map(static function (array $row): array {
                $row['amount']    = (int) $row['amount'];
                $row['daily_cap'] = (int) $row['daily_cap'];
                $row['enabled']   = (int) $row['enabled'] === 1;
                return $row;
            }, $levels->rules());
            return ['rules' => $rows, 'total' => count($rows)];
        });

        $registry->registerOperation('member.xp.rule', static function (array $in) use ($levels): array {
            $action = trim((string) ($in['action'] ?? ''));
            if ($action === '') throw new ApiOperationException('action 必填', 42201);
            $op = (string) ($in['op'] ?? 'upsert');
            $changed = $op === 'delete'
                ? $levels->deleteRule($action)
                : $levels->upsertRule(
                    $action,
                    (int) ($in['amount'] ?? 0),
                    (int) ($in['daily_cap'] ?? 0),
                    (bool) ($in['enabled'] ?? true),
                    (string) ($in['description'] ?? '')
                );
            return ['action' => $action, 'op' => $op, 'changed' => $changed];
        });

        $registry->registerOperation('member.title', static function (array $in) use ($titles): array {
            $key = trim((string) ($in['title_key'] ?? ''));
            if ($key === '') throw new ApiOperationException('title_key 必填', 42201);
            $op = (string) ($in['op'] ?? 'upsert');
            $changed = $op === 'delete' ? $titles->delete($key) : $titles->upsert([
                'title_key'   => $key,
                'name'        => (string) ($in['name'] ?? ''),
                'description' => (string) ($in['description'] ?? ''),
                'color'       => (string) ($in['color'] ?? ''),
                'priority'    => (int) ($in['priority'] ?? 0),
                'visible'     => (int) ($in['visible'] ?? 1),
                'min_level'   => (int) ($in['min_level'] ?? 0),
            ]);
            return ['title_key' => $key, 'op' => $op, 'changed' => $changed];
        });

        $registry->registerOperation('member.title.grant', static function (array $in) use ($titles, $profile): array {
            $identifier = trim((string) ($in['user_id'] ?? ''));
            $key        = trim((string) ($in['title_key'] ?? ''));
            if ($identifier === '' || $key === '') {
                throw new ApiOperationException('user_id 与 title_key 必填', 42201);
            }
            $user = $profile->resolve($identifier);
            if ($user === null && !ctype_digit($identifier)) {
                throw new ApiOperationException('用户不存在', 40403);
            }
            $userId = $user === null ? $identifier : (string) ($user['id'] ?? $identifier);
            $op = (string) ($in['op'] ?? 'grant');
            $changed = $op === 'revoke'
                ? $titles->revoke($userId, $key)
                : $titles->grant($userId, $key, (string) ($in['_identity']['username'] ?? 'system'));
            return ['user_id' => $userId, 'title_key' => $key, 'op' => $op, 'changed' => $changed];
        });

        $registry->registerOperation('member.level', static function (array $in) use ($levels): array {
            $level = (int) ($in['level'] ?? 0);
            if ($level < 1) throw new ApiOperationException('level 必填且 ≥ 1', 42201);
            $op = (string) ($in['op'] ?? 'upsert');
            $changed = $op === 'delete'
                ? $levels->deleteLevel($level)
                : $levels->upsertLevel(
                    $level,
                    (string) ($in['name'] ?? ''),
                    (int) ($in['min_xp'] ?? 0),
                    (string) ($in['color'] ?? '')
                );
            return ['level' => $level, 'op' => $op, 'changed' => $changed];
        });
    }
}
