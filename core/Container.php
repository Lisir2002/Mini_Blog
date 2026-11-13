<?php
declare(strict_types=1);
namespace Wx\Core;

use RuntimeException;

/**
 * 契约容器 —— 「万象皆接口」的落点之一。
 *
 * 核心只认接口（契约），不认具体实现。任何系统层 / 主题 / 插件
 * 都可以用显式更高的 priority 覆盖已有实现，从而实现「高度自定义」。
 * 覆盖行为留有痕迹，不静默发生。
 */
final class Container
{
    /** @var array<string, array{factory: callable, owner: string, priority: int, singleton: bool}> */
    private array $bindings = [];
    private array $instances = [];
    /** @var array<string, true> 解析进行中的契约，用于循环依赖检测 */
    private array $resolving = [];
    /** @var list<array{contract: string, from: string, to: string, priority: int}> */
    private array $overrides = [];

    /** @var callable|null 可编程裁决点：核心只询问「这次覆盖是否被允许」，不解释原因 */
    private $overrideGuard;

    /** @param callable(string, string, string, int): bool|null $overrideGuard */
    public function __construct(?callable $overrideGuard = null)
    {
        $this->overrideGuard = $overrideGuard;
    }

    public function bind(
        string $contract,
        callable $factory,
        string $owner = 'core',
        int $priority = 10,
        bool $singleton = false
    ): void {
        if (isset($this->bindings[$contract])) {
            $old = $this->bindings[$contract];
            if ($priority <= $old['priority']) {
                throw new RuntimeException(sprintf(
                    '契约冲突：%s 已由 %s (priority=%d) 实现，%s (priority=%d) 无权覆盖；请显式声明更高 priority。',
                    $contract, $old['owner'], $old['priority'], $owner, $priority
                ));
            }
            // 「可换实现」不等于「可随意提权」：覆盖前先过可编程裁决点
            if ($this->overrideGuard !== null
                && ($this->overrideGuard)($contract, $old['owner'], $owner, $priority) === false) {
                throw new RuntimeException('契约覆盖被权限策略拒绝：' . $contract);
            }
            $this->overrides[] = [
                'contract' => $contract, 'from' => $old['owner'],
                'to' => $owner, 'priority' => $priority,
            ];
            unset($this->instances[$contract]);
        }
        $this->bindings[$contract] = [
            'factory' => $factory, 'owner' => $owner,
            'priority' => $priority, 'singleton' => $singleton,
        ];
    }

    public function singleton(string $contract, callable $factory, string $owner = 'core', int $priority = 10): void
    {
        $this->bind($contract, $factory, $owner, $priority, true);
    }

    public function has(string $contract): bool { return isset($this->bindings[$contract]); }

    public function resolve(string $contract): object
    {
        // 循环依赖检测：工厂在构造期内又解析回自身（或形成环）会无限递归直到栈溢出，
        // 且报错信息毫无指向。先在 resolving 集合里打标，遇重复即抛清晰错误。
        if (isset($this->resolving[$contract])) {
            $chain = implode(' -> ', array_keys($this->resolving)) . ' -> ' . $contract;
            throw new RuntimeException('契约循环依赖：' . $chain);
        }
        if (!isset($this->bindings[$contract])) {
            throw new RuntimeException(
                '契约未实现：' . $contract . '。请接入提供该契约的系统层，或由主题 / 插件自行绑定。'
            );
        }
        $b = $this->bindings[$contract];
        if ($b['singleton'] && isset($this->instances[$contract])) {
            return $this->instances[$contract];
        }
        $this->resolving[$contract] = true;
        try {
            $object = ($b['factory'])();
        } finally {
            unset($this->resolving[$contract]);
        }
        if (!is_object($object)) {
            throw new RuntimeException($contract . ' 的工厂必须返回一个对象');
        }
        if ($b['singleton']) {
            $this->instances[$contract] = $object;
        }
        return $object;
    }

    public function ownerOf(string $contract): ?string { return $this->bindings[$contract]['owner'] ?? null; }
    public function contracts(): array { return array_keys($this->bindings); }
    public function overrides(): array { return $this->overrides; }
}
