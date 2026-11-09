<?php
declare(strict_types=1);
namespace Wx\Systems\Release;

use Throwable;
use Wx\Contracts\LifecycleManagerInterface;

/**
 * 版本编排者 —— 把「版本清单 / 迁移 / 文件快照 / 健康门禁 / 历史」串成一次升级或回退。
 *
 * 与 lifecycle 的分工：lifecycle 只管文件装卸（备份、替换、还原）；
 * 本类决定「换到哪个版本、数据库要跟着做什么、失败时按什么顺序退」。
 * 因此本类不出现一次 copy / unlink / mkdir。
 *
 * 顺序是刻意的：
 *   升级 = 先迁移（新表供新代码用）→ 换文件 → 健康检查；
 *   回退 = 先预检不可逆迁移 → 换回旧文件 → 再回退数据库。
 * 任何一步失败都逆向退到底，并把失败写进历史，绝不留下「代码退了库没退」的静默状态。
 */
final class ReleaseManager
{
    public function __construct(
        private string $root,
        private LifecycleManagerInterface $lifecycle,
        private MigrationRunner $migrations,
        private MigrationStoreInterface $store,
        private HealthProbe $health,
        private ReleaseLog $log,
        private ReleaseUnits $units,
        private array $options = []
    ) {}

    /** @return array<string, mixed> */
    public function status(): array
    {
        $manifest = ReleaseManifest::read($this->root . '/core');
        $applied = $this->store->all();
        $batches = $this->migrations->batches();
        $pending = $this->migrations->pending();
        $version = ReleaseManifest::version($manifest);
        $releases = array_map(static function (array $r) use ($version): array {
            $r['current'] = ((string) ($r['version'] ?? '')) === $version;
            return $r;
        }, $this->lifecycle->releases('core', 'core'));
        return [
            'version'      => $version,
            'released_at'  => (string) ($manifest['released_at'] ?? ''),
            'min_php'      => (string) ($manifest['min_php'] ?? ''),
            'schema'       => [
                'applied'   => count($applied),
                'batches'   => count($batches),
                'latest'    => $batches === [] ? 0 : (int) array_key_last($batches),
            ],
            'pending'      => array_column($pending, 'id'),
            'releases'     => $releases,
            'last'         => $this->log->all(1)[0] ?? null,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function history(int $limit = 20): array
    {
        return $this->log->all($limit);
    }

    /**
     * 只读预检：不写文件、不动数据库，先把「会发生什么」算清楚。
     * @return array<string, mixed>
     */
    public function plan(string $source): array
    {
        $prepared = $this->prepare($source);
        return [
            'from'       => $prepared['from'],
            'to'         => $prepared['to'],
            'migrations' => [
                'pending' => array_column($prepared['pending'], 'id'),
                'total'   => count($prepared['scripts']),
            ],
            'units'      => array_map(
                static fn(array $u): array => ['slug' => $u['slug'], 'from' => $u['from'], 'to' => $u['to']],
                $prepared['units']
            ),
            'conflicts'  => $prepared['conflicts'],
            'reversible' => $this->migrations->canRevert(
                array_column($prepared['pending'], 'id'), $prepared['scripts']
            ) === [],
        ];
    }

    /** @return array<string, mixed> */
    public function upgrade(string $source): array
    {
        $plan = $this->prepare($source);
        if ($plan['conflicts'] !== [] && ($this->options['strict_checksum'] ?? true) !== false) {
            throw new ReleaseException(
                '迁移脚本在应用后被修改，拒绝升级：' . implode('、', array_column($plan['conflicts'], 'id')), 40904
            );
        }
        $batch = $this->store->nextBatch();
        $applied = [];
        $switched = [];
        $coreSwitched = false;
        try {
            $applied = $this->migrations->apply($plan['pending'], $batch);
            foreach ($plan['units'] as $unit) {
                $this->lifecycle->upgrade('system', $unit['slug'], $unit['dir']);
                $switched[] = $unit;
            }
            $this->lifecycle->upgrade('core', 'core', rtrim($source, '/') . '/core');
            $coreSwitched = true;
            $health = $this->health->run();
            if (!$health['ok']) {
                throw new ReleaseException('升级后健康检查未通过，已自动回退', 50003);
            }
        } catch (Throwable $e) {
            $this->recover($applied, $coreSwitched, $switched, $plan['from']);
            $this->log->append([
                'action' => 'upgrade', 'from' => $plan['from'], 'to' => $plan['to'],
                'batch' => $batch, 'migrations' => $applied, 'units' => $switched,
                'result' => 'failed', 'note' => $e->getMessage(),
            ]);
            throw $e;
        }
        $this->log->append([
            'action' => 'upgrade', 'from' => $plan['from'], 'to' => $plan['to'],
            'batch' => $batch, 'migrations' => $applied, 'units' => $switched,
            'result' => 'ok', 'health' => $health['checks'],
        ]);
        return ['version' => $plan['to'], 'from' => $plan['from'], 'batch' => $batch,
                'migrations' => $applied, 'units' => $switched, 'health' => $health];
    }

    /** @return array<string, mixed> */
    public function rollback(string $target): array
    {
        $current = ReleaseManifest::version(ReleaseManifest::read($this->root . '/core'));
        if ($target === $current) throw new ReleaseException('目标版本与当前版本相同：' . $target, 40901);
        $entry = $this->lastUpgradeTo($current);
        if ($entry === null) {
            throw new ReleaseException('缺少当前版本的升级记录，无法确定要回退哪些迁移', 40901);
        }
        $ids = (array) ($entry['migrations'] ?? []);
        // 回退脚本来自「当前还在运行的版本」：代码一旦换回旧版，旧版目录里没有这些 down 文件。
        $scripts = $this->migrations->scan();
        $blocked = $this->migrations->canRevert($ids, $scripts);
        if ($blocked !== []) {
            throw new ReleaseException('迁移不可逆，拒绝回退：' . implode('、', $blocked), 40903);
        }
        $this->lifecycle->rollback('core', 'core', $target);
        foreach (array_reverse((array) ($entry['units'] ?? [])) as $unit) {
            $this->lifecycle->rollback('system', (string) $unit['slug'], (string) $unit['from']);
        }
        try {
            $reverted = $this->migrations->revert($ids, $scripts);
        } catch (Throwable $e) {
            $this->log->append(['action' => 'rollback', 'from' => $current, 'to' => $target,
                'migrations' => $ids, 'result' => 'failed', 'note' => $e->getMessage() . '（代码已回退，数据库未回退）']);
            throw $e;
        }
        $health = $this->health->run();
        $this->log->append(['action' => 'rollback', 'from' => $current, 'to' => $target,
            'migrations' => $reverted['reverted'], 'result' => $health['ok'] ? 'ok' : 'failed',
            'health' => $health['checks']]);
        return ['version' => $target, 'from' => $current,
                'reverted' => $reverted['reverted'], 'health' => $health];
    }

    /**
     * @return array{from: string, to: string, pending: list<array<string, mixed>>,
     *               scripts: array<string, array<string, mixed>>, units: list<array<string, mixed>>,
     *               conflicts: list<array<string, mixed>>}
     */
    private function prepare(string $source): array
    {
        $from = ReleaseManifest::read($this->root . '/core');
        $to = ReleaseManifest::read(rtrim($source, '/') . '/core');
        ReleaseManifest::assertUpgradable($from, $to, $this->options);
        $scripts = $this->migrations->scan($source);
        return [
            'from'      => ReleaseManifest::version($from),
            'to'        => ReleaseManifest::version($to),
            'pending'   => $this->migrations->pending($scripts),
            'scripts'   => $scripts,
            'units'     => $this->units->diff(rtrim($source, '/')),
            'conflicts' => $this->migrations->conflicts(),
        ];
    }

    private function recover(array $applied, bool $coreSwitched, array $units, string $from): void
    {
        if ($coreSwitched) {
            try {
                $this->lifecycle->rollback('core', 'core', $from);
            } catch (Throwable) {
                // 文件已停在中间态：健康门禁会拦住后续请求，历史里有记录可人工介入
            }
        }
        foreach (array_reverse($units) as $unit) {
            try {
                $this->lifecycle->rollback('system', (string) $unit['slug'], (string) $unit['from']);
            } catch (Throwable) {
                // 同 core：保留现场，交给健康门禁与历史记录
            }
        }
        if ($applied === []) return;
        try {
            $this->migrations->revert($applied);
        } catch (Throwable) {
            // 不可逆迁移：保留台账，宁可让运维看到「已应用」也不要假装回退成功
        }
    }

    /** @return array<string, mixed>|null */
    private function lastUpgradeTo(string $version): ?array
    {
        foreach ($this->log->all(200) as $row) {
            if (($row['action'] ?? '') === 'upgrade' && ($row['result'] ?? '') === 'ok'
                && (string) ($row['to'] ?? '') === $version) {
                return $row;
            }
        }
        return null;
    }
}
