<?php
declare(strict_types=1);
namespace Wx\Systems\Member;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Systems\Api\ApiOperationException;

/**
 * 成员系统的只读接口 —— 档案、排行榜、头衔目录、等级表。
 *
 * 这四个接口都不写数据，因此不设权限点：公开站点需要展示作者等级与头衔。
 * 唯一的裁剪是头衔目录默认只返回 visible=1 的条目，隐藏头衔需显式 include_hidden。
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
            if ($user === null && !ctype_digit($identifier)) {
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
