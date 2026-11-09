<?php
declare(strict_types=1);
namespace Wx\Systems\Release;

use Wx\Contracts\DatabaseInterface;
use Wx\Core\HookBus;
use Wx\Systems\Database\SqlScript;

/**
 * 迁移执行者 —— 版本系统的核心事实来源。
 *
 * 三条不可让步的规则（与 Flyway / Alembic 的实践一致）：
 * 1. 只执行台账里没有的脚本，顺序由文件名决定，不靠「当前版本号猜」；
 * 2. 已执行脚本的 checksum 变了就报错，绝不静默重跑（历史不可改写）；
 * 3. 回退必须逐条有 down 脚本，缺一条就整批拒绝，不做「回退了但数据库没回」的假成功。
 */
final class MigrationRunner
{
    public function __construct(
        private string $root,
        private ?DatabaseInterface $db,
        private MigrationStoreInterface $store,
        private HookBus $hooks
    ) {}

    public function role(): string { return 'migration_runner'; }

    /**
     * 扫描某个根目录下的全部迁移脚本（systems/＊、core）。
     * @return array<string, array{id: string, owner: string, file: string, down: ?string, checksum: string}>
     */
    public function scan(?string $root = null): array
    {
        $base = rtrim($root ?? $this->root, '/');
        $out = [];
        foreach (glob($base . '/systems/*/migrations', GLOB_ONLYDIR) ?: [] as $dir) {
            $owner = strtolower(basename(dirname($dir)));
            foreach (glob($dir . '/*.sql') ?: [] as $file) {
                $script = $this->describe($owner, $file);
                if ($script !== null) $out[$script['id']] = $script;
            }
        }
        foreach (glob($base . '/core/migrations/*.sql') ?: [] as $file) {
            $script = $this->describe('core', $file);
            if ($script !== null) $out[$script['id']] = $script;
        }
        ksort($out);
        return $out;
    }

    /** @param array<string, array<string, mixed>>|null $scripts @return list<array<string, mixed>> */
    public function pending(?array $scripts = null): array
    {
        $out = [];
        foreach ($scripts ?? $this->scan() as $script) {
            if ($this->store->find($script['id']) === null) $out[] = $script;
        }
        return $out;
    }

    /**
     * 已执行脚本被改动 → 冲突。返回冲突清单，调用方必须拒绝继续。
     * @param array<string, array<string, mixed>>|null $scripts
     * @return list<array{id: string, expected: string, actual: string}>
     */
    public function conflicts(?array $scripts = null): array
    {
        $out = [];
        foreach ($scripts ?? $this->scan() as $script) {
            $row = $this->store->find($script['id']);
            if ($row !== null && $row['checksum'] !== $script['checksum']) {
                $out[] = ['id' => $script['id'], 'expected' => $row['checksum'], 'actual' => $script['checksum']];
            }
        }
        return $out;
    }

    /**
     * 执行一批脚本；每条执行成功立即入账，失败即停（已执行的保留在台账里，便于精确回退）。
     * @param list<array<string, mixed>> $scripts
     * @return list<string> 已应用的 id
     */
    public function apply(array $scripts, int $batch): array
    {
        $db = $this->db ?? throw new ReleaseException('无数据库连接，迁移不可执行', 50004);
        $applied = [];
        foreach ($scripts as $script) {
            $statements = SqlScript::parse($db, (string) ($script['up_sql'] ?? ''));
            try {
                foreach ($statements as $sql) {
                    $db->pdo()->exec($sql);
                }
            } catch (\Throwable $e) {
                $this->hooks->emit('release.migration.failed', $script['id'], $e->getMessage());
                throw new ReleaseException(
                    '迁移执行失败：' . $script['id'] . '（' . $e->getMessage() . '）', 50002
                );
            }
            $this->store->record(
                (string) $script['id'], (string) $script['owner'],
                (string) $script['checksum'], $batch
            );
            $applied[] = (string) $script['id'];
            $this->hooks->emit('release.migration.applied', $script['id'], $batch);
        }
        return $applied;
    }

    /**
     * 逆序回退指定 id；任何一条缺 down 脚本就整批拒绝（先检查再执行）。
     * @param list<string> $ids @param array<string, array<string, mixed>>|null $scripts
     * @return array{reverted: list<string>, batch: int}
     */
    public function revert(array $ids, ?array $scripts = null): array
    {
        $db = $this->db ?? throw new ReleaseException('无数据库连接，迁移不可回退', 50004);
        $scripts ??= $this->scan();
        $plan = [];
        $blocked = [];
        foreach (array_reverse($ids) as $id) {
            $script = $scripts[$id] ?? null;
            if ($script === null || $script['down'] === null) $blocked[] = $id;
            else $plan[] = $script;
        }
        if ($blocked !== []) {
            throw new ReleaseException(
                '迁移不可逆，拒绝回退：' . implode('、', $blocked) . '（缺少 .down.sql）', 40903
            );
        }
        $batch = 0;
        foreach ($plan as $script) {
            foreach (SqlScript::parse($db, (string) ($script['down_sql'] ?? '')) as $sql) {
                $db->pdo()->exec($sql);
            }
            $row = $this->store->find((string) $script['id']);
            $batch = max($batch, (int) ($row['batch'] ?? 0));
            $this->store->forget((string) $script['id']);
            $this->hooks->emit('release.migration.reverted', $script['id']);
        }
        return ['reverted' => array_column($plan, 'id'), 'batch' => $batch];
    }

    /**
     * 安装基线：全新站点首次启动时，把「当前代码里已有的全部脚本」登记为已执行，
     * 但不重跑（表已由各系统层 ensureSchema 幂等建好）。
     * 否则第一次升级会把建站以来的所有迁移重放一遍，播种类脚本会被重复执行。
     * @return int 登记的脚本数
     */
    public function baseline(): int
    {
        $count = 0;
        foreach ($this->scan() as $script) {
            if ($this->store->find($script['id']) !== null) continue;
            $this->store->record($script['id'], $script['owner'], $script['checksum'], 0);
            $count++;
        }
        if ($count > 0) $this->hooks->emit('release.migration.baseline', $count);
        return $count;
    }

    /** @return array<int, list<string>> 批次号 => 该批次的迁移 id */
    public function batches(): array
    {
        $out = [];
        foreach ($this->store->all() as $row) {
            $out[(int) $row['batch']][] = (string) $row['id'];
        }
        ksort($out);
        return $out;
    }

    /**
     * 预检：返回缺少 down 脚本的 id。调用方必须在动文件之前调用，
     * 否则会出现「代码回退了、数据库没回」的半截状态。
     * @param list<string> $ids @param array<string, array<string, mixed>>|null $scripts
     * @return list<string>
     */
    public function canRevert(array $ids, ?array $scripts = null): array
    {
        $scripts ??= $this->scan();
        $blocked = [];
        foreach ($ids as $id) {
            if (($scripts[$id]['down'] ?? null) === null) $blocked[] = $id;
        }
        return $blocked;
    }

    /** @return array<string, mixed>|null */
    private function describe(string $owner, string $file): ?array
    {
        if (str_ends_with($file, '.down.sql')) return null;
        $name = basename($file);
        $down = substr($file, 0, -4) . '.down.sql';
        return [
            'id'       => $owner . '/' . $name,
            'owner'    => $owner,
            'file'     => $file,
            'down'     => is_file($down) ? $down : null,
            // 内容在扫描时固定：回退时文件可能已随单元回退消失，但 SQL 仍可执行。
            'up_sql'   => (string) file_get_contents($file),
            'down_sql' => is_file($down) ? (string) file_get_contents($down) : null,
            'checksum' => SqlScript::checksum($file),
        ];
    }
}
