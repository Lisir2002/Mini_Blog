<?php
declare(strict_types=1);
namespace Wx\Systems\Theme;

use Wx\Core\Config;

/**
 * 静态主题清单 —— 只读各主题包的 theme.json 与合并后的 theme.active。
 *
 * 不引导内核、不连库、不装配主题包：清单是出厂事实，运行时装配是另一回事。
 * Config 已合并 config/local.php 覆盖层，所以启用态只有一个事实源。
 */
final class ThemeCatalog implements ThemeCatalogInterface
{
    public function __construct(private Config $config, private string $root) {}

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $active = $this->active();
        $out = [];
        foreach (glob($this->root . '/themes/*/theme.json') ?: [] as $file) {
            $meta = json_decode((string) file_get_contents($file), true);
            if (!is_array($meta)) continue;
            $slug = (string) ($meta['slug'] ?? basename(dirname($file)));
            $out[] = [
                'slug'    => $slug,
                'version' => (string) ($meta['version'] ?? ''),
                'enabled' => ($meta['enabled'] ?? true) === true,
                'meta'    => !empty($meta['meta']),
                'surface' => (string) ($meta['surface'] ?? ''),
                'family'  => (string) ($meta['family'] ?? ''),
                'layouts' => array_values((array) ($meta['layouts'] ?? [])),
                'active'  => $slug === $active,
            ];
        }
        usort($out, static fn(array $a, array $b): int => strcmp((string) $a['slug'], (string) $b['slug']));
        return $out;
    }

    public function active(): ?string
    {
        $value = $this->config->get('theme.active');
        return ($value === null || $value === '' || $value === false) ? null : (string) $value;
    }

    public function has(string $slug): bool
    {
        foreach ($this->all() as $theme) {
            if ($theme['slug'] === $slug && $theme['enabled'] === true) return true;
        }
        return false;
    }
}
