<?php
declare(strict_types=1);
namespace Wx\Systems\Release;

use Wx\Contracts\ApiRegistryInterface;

/**
 * 版本层接口执行者 —— 五个 operation，全部是薄封装。
 * 执行者不认识 HTTP，也不做权限判断：鉴权在 RequestGuard，决策在 permission 层。
 */
final class ReleaseOperations
{
    public function __construct(private ReleaseManager $manager) {}

    public function role(): string { return 'release_operations'; }

    public function register(ApiRegistryInterface $registry): void
    {
        $m = $this->manager;
        $registry->registerOperation('release.status', static fn(array $in): array => $m->status());
        $registry->registerOperation('release.history', static fn(array $in): array => [
            'history' => $m->history((int) ($in['limit'] ?? 20)),
        ]);
        $registry->registerOperation('release.plan',
            static fn(array $in): array => $m->plan((string) $in['source']));
        $registry->registerOperation('release.upgrade',
            static fn(array $in): array => $m->upgrade((string) $in['source']));
        $registry->registerOperation('release.rollback',
            static fn(array $in): array => $m->rollback((string) $in['version']));
    }
}
