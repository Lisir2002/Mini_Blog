<?php
declare(strict_types=1);
namespace Wx\Systems\Lifecycle;

use Throwable;

/**
 * 版本切换编排 —— 升级与回退的完整链路，与「安装 / 卸载」分开。
 *
 * 拆开的理由：安装关心「从无到有」，版本关心「从旧到新、从新回旧」，
 * 后者的失败面更大（备份、快照、状态、钩子、历史），混在一个类里会同时
 * 越过行数与职权跨度两条预算（规矩 H）。
 */
final class VersionSwitcher
{
    public function __construct(
        private UnitCatalog $catalog,
        private StateStore $state,
        private FileTransaction $tx,
        private PackageVerifier $verifier,
        private HookRunner $runner,
        private StateMachine $machine,
        private ReleaseArchive $archive,
        private array $options = []
    ) {}

    public function role(): string { return 'version_switcher'; }

    /** @return array<string, mixed> */
    public function upgrade(string $type, string $slug, string $source): array
    {
        $this->catalog->assertType($type);
        $this->catalog->assertSlug($slug);
        if ($type === 'core' && !($this->options['allow_core_update'] ?? false)) {
            throw new LifecycleException('站点程序升级默认关闭，请在 config.lifecycle.allow_core_update 显式开启', 40301);
        }
        $key = $this->catalog->key($type, $slug);
        $this->machine->assertTransition($this->current($key), 'upgrade', $key);
        $src = $this->verifier->assertSource($source);
        $manifest = $this->catalog->manifestOf($src, $type);
        $newVersion = $this->catalog->versionOf($manifest);
        $target = $this->catalog->unitDir($type, $slug);
        if (!$this->tx->exists($target)) throw new LifecycleException('单元不存在：' . $key, 40402);
        $record = $this->state->unit($key);
        $oldVersion = (string) ($record['version'] ?? '0.0.0');
        if (version_compare($newVersion, $oldVersion, '<=')) {
            throw new LifecycleException("新版本 {$newVersion} 不高于当前版本 {$oldVersion}", 40901);
        }
        $this->verifier->assertIntegrity($src);
        $this->runner->before('upgrade', $type, $slug, $oldVersion, $newVersion);
        // 快照按版本归档，而不是单个 backup 字段：回退一次之后仍能回到更早的版本。
        $backup = $this->archive->snapshot($target, $key, $oldVersion);
        $ctx = ['type' => $type, 'slug' => $slug, 'from' => $oldVersion, 'to' => $newVersion];
        try {
            $this->tx->replace($src, $target, $backup, $key, fn() => $this->runner->package($target, 'on_upgrade', $ctx));
        } catch (Throwable $e) {
            $this->runner->failed('upgrade', $key, $e->getMessage());
            throw $e;
        }
        $updated = array_merge($record, ['type' => $type, 'slug' => $slug, 'version' => $newVersion,
            'state' => (string) ($record['state'] ?? 'installed'), 'backup' => $backup,
            'releases' => $this->archive->all($key), 'updated_at' => date(DATE_ATOM)]);
        // F6：升级落位后基线必须与新目录逐字对应（含 on_upgrade 钩子产物）。
        $updated['integrity'] = Fs::baseline($target, (string) ($this->options['signature_secret'] ?? ''));
        $this->commit($key, $updated, 'upgrade', $oldVersion, $newVersion, ['backup' => $backup]);
        return $updated;
    }

    /** @return array<string, mixed> */
    public function rollback(string $type, string $slug, ?string $version = null): array
    {
        $this->catalog->assertType($type);
        $this->catalog->assertSlug($slug);
        $key = $this->catalog->key($type, $slug);
        $this->machine->assertTransition($this->current($key), 'rollback', $key);
        $record = $this->state->unit($key);
        $current = (string) ($record['version'] ?? '');
        if ($version !== null && $version === $current) {
            throw new LifecycleException('目标版本与当前版本相同：' . $version, 40901);
        }
        $snapshot = $this->pickSnapshot($key, $record, $version, $current, $type);
        $target = $this->catalog->unitDir($type, $slug);

        // 先把「现在这个版本」也留一份：回退不该是单行道，升回去时不必重新找包。
        if ($current !== '' && $current !== $snapshot['version']) {
            $this->archive->snapshot($target, $key, $current);
        }
        try {
            $this->archive->restore($snapshot['path'], $target, $key);
        } catch (Throwable $e) {
            $this->runner->failed('rollback', $key, $e->getMessage());
            throw $e;
        }
        $restored = $snapshot['version'];
        $updated = array_merge($record, ['version' => $restored, 'backup' => null,
            'releases' => $this->archive->all($key), 'updated_at' => date(DATE_ATOM)]);
        // F6：回滚也是一次合法落位，基线随快照恢复后的目录重新登记。
        $updated['integrity'] = Fs::baseline($target, (string) ($this->options['signature_secret'] ?? ''));
        $this->commit($key, $updated, 'rollback', $current, $restored, ['snapshot' => $snapshot['path']]);
        return $updated;
    }

    /** @return list<array<string, mixed>> */
    public function releases(string $type, string $slug): array
    {
        $this->catalog->assertType($type);
        $this->catalog->assertSlug($slug);
        return $this->archive->all($this->catalog->key($type, $slug));
    }

    /**
     * 选定回退目标：显式版本优先，否则最近一份；旧版单 backup 记录仍兼容。
     * @param array<string, mixed> $record
     * @return array{version: string, path: string}
     */
    private function pickSnapshot(string $key, array $record, ?string $version, string $current, string $type): array
    {
        $entries = $this->archive->all($key);
        if ($version !== null && $version !== '') {
            foreach ($entries as $entry) {
                if ($entry['version'] === $version) return ['version' => $version, 'path' => $entry['path']];
            }
            throw new LifecycleException('没有该版本的可回退快照：' . $version, 40901);
        }
        foreach ($entries as $entry) {
            if ($entry['version'] !== $current) return ['version' => $entry['version'], 'path' => $entry['path']];
        }
        $legacy = (string) ($record['backup'] ?? '');
        if ($legacy !== '' && $this->tx->exists($legacy)) {
            $legacyVersion = $this->catalog->versionOf($this->catalog->manifestOf($legacy, $type));
            return ['version' => $legacyVersion, 'path' => $legacy];
        }
        throw new LifecycleException('无可用快照，无法回滚：' . $key, 40901);
    }

    private function current(string $key): string
    {
        return (string) ($this->state->unit($key)['state'] ?? 'absent');
    }

    /** @param array<string, mixed> $record @param array<string, mixed> $extra */
    private function commit(string $key, array $record, string $operation, string $from, string $to, array $extra = []): void
    {
        $this->state->set($key, $record);
        $this->state->history($key, $operation, $from, $to, $extra);
        $this->runner->after($operation, $key, $record);
    }
}
