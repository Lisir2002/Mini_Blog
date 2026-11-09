<?php
declare(strict_types=1);
namespace Wx\Contracts;

/**
 * 装卸契约 —— 元层对外暴露的唯一能力。
 *
 * 被管单元共五类：core（站点程序）、system（系统层）、theme、plugin、page。
 * 核心只认这个接口，不认识任何具体包、任何具体文件系统操作。
 */
interface LifecycleManagerInterface
{
    /** @return list<array<string, mixed>> 所有单元及其当前状态 */
    public function units(): array;

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function install(string $type, string $source, array $options = []): array;

    /** @return array<string, mixed> */
    public function activate(string $type, string $slug): array;

    /** @return array<string, mixed> */
    public function deactivate(string $type, string $slug): array;

    /** @return array<string, mixed> */
    public function upgrade(string $type, string $slug, string $source): array;

    /** @return array<string, mixed> */
    public function uninstall(string $type, string $slug): array;

    /**
     * 回退到指定版本；省略 $version 时回退到最近一个快照。
     * 只有存在快照的版本才可回退，绝不「猜一个版本」。
     * @return array<string, mixed>
     */
    public function rollback(string $type, string $slug, ?string $version = null): array;

    /** @return list<array<string, mixed>> 可回退的版本快照，按时间倒序 */
    public function releases(string $type, string $slug): array;

    /** @return array<string, mixed> 完整性校验报告 */
    public function verify(string $type, string $slug): array;
}
