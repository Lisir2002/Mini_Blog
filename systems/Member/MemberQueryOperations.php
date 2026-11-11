<?php
declare(strict_types=1);
namespace Wx\Systems\Member;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Systems\Api\ApiOperationException;

/**
 * 成员系统的只读接口 —— 档案、排行榜、头衔目录、等级表。
 *
 * 这四个接口都不写数据，因此不按权限点裁决：公开站点需要展示作者等级与头衔。
 * 唯一的裁剪是头衔目录默认只返回 visible=1 的条目，隐藏头衔需显式 include_hidden。
 *
 * `member.read`（v0.41）是**后台视图的访问点**，不是这些接口的开关：
 * 等级表与头衔目录本来就该对读者公开，把它们改成「要权限才能读」会造出
 * 「站点上看得见、接口里读不到」的悖论。经验规则例外 —— 规则数值就是运营策略，
 * 读取它本身要求 member.xp.rule.manage。
 */
final class MemberQueryOperations
{
    public function __construct(
        private MemberProfile $profile,
        private TitleBook $titles,
        private LevelStoreInterface $levels
    ) {}

    public function role(): string { return 'member_query_operations'; }

    public function register(ApiRegistryInterface $registry): void
    {
        $profile = $this->profile;
        $titles  = $this->titles;
        $levels  = $this->levels;

        $registry->registerOperation('member.profile', static function (array $in) use ($profile): array {
            $identifier = trim((string) ($in['user_id'] ?? ''));
            if ($identifier === '') {
                $identity   = $in['_identity'] ?? null;
                $identifier = (string) ($identity['user_id'] ?? '');
            }
            if ($identifier === '') {
                throw new ApiOperationException('user_id 必填（匿名请求无法推断主体）', 42201);
            }
            $user = $profile->resolve($identifier);
            // 「查不到人」必须报 40403，而不是拿一个 0 经验 / Lv.1 的空档案冒充。
            // 只有档案在、账号没了（账号被删）才继续返回 —— 那份记录确实存在，标 orphan 说明。
            if ($user === null && !$profile->hasProfile($identifier)) {
                throw new ApiOperationException('用户不存在', 40403);
            }
            $userId = $user === null ? $identifier : (string) ($user['id'] ?? $identifier);
            return $profile->of($userId);
        });

        $registry->registerOperation('member.leaderboard', static function (array $in) use ($profile): array {
            return $profile->leaderboard((int) ($in['limit'] ?? 20), (int) ($in['offset'] ?? 0));
        });

        $registry->registerOperation('member.titles', static function (array $in) use ($titles): array {
            $includeHidden = (bool) ($in['include_hidden'] ?? false);
            $rows = $titles->definitions($includeHidden);
            return ['titles' => $rows, 'total' => count($rows)];
        });

        $registry->registerOperation('member.levels', static function (array $in) use ($levels): array {
            $rows = LevelCurve::normalize($levels->levels());
            return ['levels' => $rows, 'total' => count($rows)];
        });
    }
}
