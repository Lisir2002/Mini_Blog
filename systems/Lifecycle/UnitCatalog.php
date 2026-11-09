<?php
declare(strict_types=1);
namespace Wx\Systems\Lifecycle;

use Wx\Core\HookBus;

/**
 * 单元目录协作者 —— 唯一知道「目录 + 清单」如何变成「身份 + 路径 + 版本」的地方。
 * 身份永远取自清单 slug / name，目录名只是物理位置，不能当标识。
 */
final class UnitCatalog
{
    public const TYPES = [
        'core'   => ['dir' => 'core',    'manifest' => 'version.json'],
        'system' => ['dir' => 'systems', 'manifest' => 'system.json'],
        'theme'  => ['dir' => 'themes',  'manifest' => 'theme.json'],
        'plugin' => ['dir' => 'plugins', 'manifest' => 'plugin.json'],
        'page'   => ['dir' => 'pages',   'manifest' => 'page.json'],
    ];

    public function __construct(
        private string $root,
        private StateStore $state,
        private HookBus $hooks
    ) {}

    public function role(): string { return 'catalog'; }

    public function types(): array { return self::TYPES; }

    public function config(string $type): array
    {
        return self::TYPES[$type] ?? throw new LifecycleException('未知单元类型：' . $type, 42201);
    }

    public function assertType(string $type): void { $this->config($type); }

    public function assertSlug(string $slug): void
    {
        if ($slug === '' || str_contains($slug, "\0")
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $slug)) {
            throw new LifecycleException('非法单元标识（防目录穿越）：' . $slug, 42201);
        }
    }

    public function key(string $type, string $slug): string { return $type . ':' . $slug; }

    /** @return array<string, mixed> */
    public function manifestOf(string $dir, string $type): array
    {
        $file = $dir . '/' . $this->config($type)['manifest'];
        if (!is_file($file)) throw new LifecycleException('单元清单缺失：' . $file, 42201);
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    public function versionOf(array $manifest): string { return (string) ($manifest['version'] ?? '0.0.0'); }

    public function identityOf(array $manifest, string $dir): string
    {
        return (string) ($manifest['slug'] ?? $manifest['name'] ?? basename($dir));
    }

    /** 优先使用状态里登记的物理路径，兼容「目录名 ≠ 清单 slug」的既有包。 */
    public function unitDir(string $type, string $slug): string
    {
        $path = $this->state->unit($this->key($type, $slug))['path'] ?? null;
        if (is_string($path) && $path !== '') return $this->root . '/' . $path;
        return $this->root . '/' . $this->config($type)['dir'] . ($type === 'core' ? '' : '/' . $slug);
    }

    public function relativePath(string $dir): string
    {
        return ltrim(str_replace('\\', '/', str_replace($this->root, '', $dir)), '/');
    }

    /** @return list<array{identity: string, requires: array, dir: string}> */
    public function allManifests(): array
    {
        $out = [];
        foreach (['systems', 'themes', 'plugins', 'pages'] as $dir) {
            foreach (glob($this->root . '/' . $dir . '/*/*.json') ?: [] as $file) {
                $meta = json_decode((string) file_get_contents($file), true);
                if (!is_array($meta)) continue;
                $out[] = [
                    'identity' => (string) ($meta['slug'] ?? $meta['name'] ?? basename(dirname($file))),
                    'requires' => (array) ($meta['requires'] ?? []),
                    'dir'      => basename(dirname($file)),
                ];
            }
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function units(): array
    {
        $out = [];
        foreach (self::TYPES as $type => $cfg) {
            $dirs = $type === 'core'
                ? [$this->unitDir('core', 'core')]
                : array_map('dirname', glob($this->root . '/' . $cfg['dir'] . '/*/' . $cfg['manifest']) ?: []);
            foreach ($dirs as $dir) {
                if ($type !== 'core' || is_dir($dir)) $out[] = $this->describe($type, $dir);
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    public function describe(string $type, string $dir): array
    {
        $manifest = $this->manifestOf($dir, $type);
        $slug = $type === 'core' ? 'core' : $this->identityOf($manifest, $dir);
        $key = $this->key($type, $slug);
        $record = $this->state->unit($key);
        return [
            'unit'        => $key,
            'type'        => $type,
            'slug'        => $slug,
            'version'     => $record['version'] ?? $this->versionOf($manifest),
            'state'       => $record['state'] ?? 'installed',
            'has_backup'  => !empty($record['backup']) && is_dir((string) $record['backup']),
            'path'        => $this->relativePath($dir),
            'updated_at'  => $record['updated_at'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    public function record(string $type, string $slug, string $version, string $state, ?string $path = null): array
    {
        $now = date(DATE_ATOM);
        return [
            'type' => $type, 'slug' => $slug, 'version' => $version, 'state' => $state,
            'path' => $path ?? $this->config($type)['dir'] . ($type === 'core' ? '' : '/' . $slug),
            'installed_at' => $now, 'updated_at' => $now, 'backup' => null,
        ];
    }

    /** 磁盘清单与状态文件对账：补登记 + 版本同步（有备份的升级中间态不覆盖）。 */
    public function reconcile(): void
    {
        $this->hooks->emit('lifecycle.collab', $this->role(), 'reconcile');
        foreach ($this->units() as $unit) {
            $key = $unit['unit'];
            $disk = $this->versionOf($this->manifestOf($this->root . '/' . $unit['path'], $unit['type']));
            if (!$this->state->has($key)) {
                $this->state->set($key, $this->record(
                    $unit['type'], $unit['slug'], $disk, 'active', $unit['path']
                ));
                continue;
            }
            $record = $this->state->unit($key);
            if (($record['version'] ?? null) !== $disk && empty($record['backup'])) {
                $this->state->set($key, array_merge($record, [
                    'version' => $disk, 'updated_at' => date(DATE_ATOM),
                ]));
            }
        }
    }
}
