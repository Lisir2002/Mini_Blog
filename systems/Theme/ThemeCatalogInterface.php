<?php
declare(strict_types=1);
namespace Wx\Systems\Theme;

/**
 * 主题清单契约 —— 只读枚举出厂主题包与当前启用态。
 *
 * 由 theme 系统层自持并注册进容器；读取策略与写入策略分开，
 * 便于把「清单从哪来」「启用态写到哪」分别替换，而不牵动主题包。
 */
interface ThemeCatalogInterface
{
    /** @return list<array<string, mixed>> 每项含 slug / version / enabled / meta / surface / family / layouts / active */
    public function all(): array;

    /** 当前启用主题；null 表示零呈现 Headless */
    public function active(): ?string;

    /** 是否存在一个已启用（enabled=true）的主题包 */
    public function has(string $slug): bool;

    /** 主题包声明的实现类名；找不到返回 null（用于激活期预检，避免写盘后才发现类不可加载） */
    public function classOf(string $slug): ?string;
}
