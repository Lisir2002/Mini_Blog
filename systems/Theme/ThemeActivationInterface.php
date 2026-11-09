<?php
declare(strict_types=1);
namespace Wx\Systems\Theme;

/**
 * 主题启用写入契约 —— 唯一有权改变「当前启用主题」的入口。
 *
 * 与 ThemeCatalogInterface 分开：读清单是公开只读能力，写启用态是可替换的
 * 运维策略（本地覆盖层 / 数据库 / 环境注入），两者不应被同一个实现捆死。
 */
interface ThemeActivationInterface
{
    /** @return array{active: ?string, previous: ?string, changed: bool, effective: string} */
    public function activate(?string $slug): array;
}
