<?php
declare(strict_types=1);
namespace Wx\Systems\Lifecycle;

use Throwable;
use Wx\Contracts\LifecycleManagerInterface;
use Wx\Core\HookBus;

/**
 * 装卸编排者 —— 元层对外的唯一实现。
 *
 * 本文件只做流程编排：向协作者发指令、汇总结果、提交状态。
 * 状态裁决、清单身份、依赖图、验签、文件事务、钩子执行全部委托出去，
 * 因此这里不出现任何文件操作、加密、路径校验或 SQL。
 *
 * 协作者各自在关键方法里自报 `lifecycle.collab` 轨迹，
 * 守卫测试借此验证「拆开之后确实仍在协作」（规矩 I 的 C8）。
 */
final class LifecycleManager implements LifecycleManagerInterface
{
    private StateStore $state;
    private UnitCatalog $catalog;
    private DependencyGraph $graph;
    private PackageVerifier $verifier;
    private FileTransaction $tx;
    private HookRunner $runner;
    private StateMachine $machine;
    private ReleaseArchive $archive;
    private VersionSwitcher $versions;

    /**
     * @param array<string, mixed> $options
     * @param array<string, object> $collaborators 可注入协作者，便于独立验证协作关系
     */
    public function __construct(
        private string $root,
        private HookBus $hooks,
        private array $options = [],
        array $collaborators = []
    ) {
        $base = $root . '/storage/lifecycle';
        $this->state    = $collaborators['state_store'] ?? new StateStore($base, $hooks);
        $this->catalog  = $collaborators['unit_catalog'] ?? new UnitCatalog($root, $this->state, $hooks);
        $this->graph    = $collaborators['dependency_graph'] ?? new DependencyGraph($root, $this->catalog, $hooks);
        $this->verifier = $collaborators['package_verifier'] ?? new PackageVerifier($root, $hooks, $options);
        $this->tx       = $collaborators['file_transaction'] ?? new FileTransaction($base, $hooks);
        $this->runner   = $collaborators['hook_runner'] ?? new HookRunner($hooks);
        $this->machine  = $collaborators['state_machine'] ?? new StateMachine($hooks);
        $this->archive  = $collaborators['release_archive']
            ?? new ReleaseArchive($base, $hooks, (int) ($options['keep_releases'] ?? 5));
        $this->versions = $collaborators['version_switcher'] ?? new VersionSwitcher(
            $this->catalog, $this->state, $this->tx, $this->verifier,
            $this->runner, $this->machine, $this->archive, $options
        );
        $this->catalog->reconcile();
    }

    /** @return array<string, object> 全部协作者，供守卫测试逐个核对职责归属 */
    public function collaborators(): array
    {
        return [
            'state_store' => $this->state, 'unit_catalog' => $this->catalog,
            'dependency_graph' => $this->graph, 'package_verifier' => $this->verifier,
            'file_transaction' => $this->tx, 'hook_runner' => $this->runner,
            'state_machine' => $this->machine, 'release_archive' => $this->archive,
            'version_switcher' => $this->versions,
        ];
    }

    public function units(): array { return $this->catalog->units(); }

    public function stateOf(string $type, string $slug): string { return $this->current($this->catalog->key($type, $slug)); }

    public function install(string $type, string $source, array $options = []): array
    {
        $this->catalog->assertType($type);
        if ($type === 'core') throw new LifecycleException('站点程序只能升级或回滚，不可重新安装', 40901);
        $src = $this->verifier->assertSource($source);
        $manifest = $this->catalog->manifestOf($src, $type);
        $slug = (string) ($options['slug'] ?? $manifest['slug'] ?? $this->catalog->identityOf($manifest, $src));
        $this->catalog->assertSlug($slug);
        $key = $this->catalog->key($type, $slug);
        $this->machine->assertTransition($this->current($key), 'install', $key);
        $target = $this->catalog->unitDir($type, $slug);
        if ($this->tx->exists($target)) throw new LifecycleException('目标单元已存在：' . $key, 40901);
        $this->graph->assertSatisfied($manifest);
        $this->verifier->assertIntegrity($src);
        $this->runner->before('install', $type, $slug, $manifest);
        $version = $this->catalog->versionOf($manifest);
        $ctx = ['type' => $type, 'slug' => $slug, 'version' => $version];
        try {
            $this->tx->install($src, $target, $key, fn() => $this->runner->package($target, 'on_install', $ctx));
        } catch (Throwable $e) {
            $this->runner->failed('install', $key, $e->getMessage());
            throw $e;
        }
        $record = $this->catalog->record($type, $slug, $version, 'installed');
        $this->commit($key, $record, 'install', 'absent', 'installed');
        return $record;
    }

    public function activate(string $type, string $slug): array
    {
        return $this->state->unit($this->changeState($type, $slug, 'activate', 'active'));
    }

    public function deactivate(string $type, string $slug): array
    {
        return $this->state->unit($this->changeState($type, $slug, 'deactivate', 'installed'));
    }

    public function upgrade(string $type, string $slug, string $source): array
    {
        return $this->versions->upgrade($type, $slug, $source);
    }

    public function uninstall(string $type, string $slug): array
    {
        $this->catalog->assertType($type);
        $this->catalog->assertSlug($slug);
        if ($type === 'core') throw new LifecycleException('站点程序不可卸载，只能升级或回滚', 40301);
        $key = $this->catalog->key($type, $slug);
        $this->machine->assertTransition($this->current($key), 'uninstall', $key);
        $target = $this->catalog->unitDir($type, $slug);
        $this->graph->assertNoReverse($type, $slug);
        $this->runner->before('uninstall', $type, $slug);
        $this->runner->package($target, 'on_uninstall', ['type' => $type, 'slug' => $slug]);
        $this->tx->remove($target);
        $this->state->remove($key);
        $this->state->history($key, 'uninstall', 'installed', 'absent');
        $this->runner->after('uninstall', $key, $done = ['unit' => $key, 'state' => 'absent']);
        return $done + ['removed' => true];
    }

    public function rollback(string $type, string $slug, ?string $version = null): array
    {
        return $this->versions->rollback($type, $slug, $version);
    }

    public function releases(string $type, string $slug): array
    {
        return $this->versions->releases($type, $slug);
    }

    public function verify(string $type, string $slug): array
    {
        $this->catalog->assertType($type);
        $this->catalog->assertSlug($slug);
        $target = $this->catalog->unitDir($type, $slug);
        if (!$this->tx->exists($target)) {
            throw new LifecycleException('单元不存在：' . $this->catalog->key($type, $slug), 40402);
        }
        return $this->verifier->verify($target);
    }

    private function current(string $key): string
    {
        return (string) ($this->state->unit($key)['state'] ?? 'absent');
    }

    private function changeState(string $type, string $slug, string $operation, string $to): string
    {
        $this->catalog->assertType($type);
        $this->catalog->assertSlug($slug);
        $key = $this->catalog->key($type, $slug);
        $from = $this->current($key);
        $this->machine->assertTransition($from, $operation, $key);
        if (!$this->tx->exists($this->catalog->unitDir($type, $slug))) {
            throw new LifecycleException('单元不存在：' . $key, 40402);
        }
        $this->runner->before($operation, $type, $slug);
        $updated = array_merge($this->state->unit($key), ['type' => $type, 'slug' => $slug,
            'state' => $to, 'updated_at' => date(DATE_ATOM)]);
        $this->commit($key, $updated, $operation, $from, $to);
        return $key;
    }

    /** @param array<string, mixed> $record @param array<string, mixed> $extra */
    private function commit(string $key, array $record, string $operation, string $from, string $to, array $extra = []): void
    {
        $this->state->set($key, $record);
        $this->state->history($key, $operation, $from, $to, $extra);
        $this->runner->after($operation, $key, $record);
    }
}
