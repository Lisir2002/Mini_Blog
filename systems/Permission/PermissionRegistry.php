<?php
declare(strict_types=1);
namespace Wx\Systems\Permission;

use RuntimeException;

/**
 * 权限点注册表 —— 汇总各系统层 permissions.json 的自报声明。
 *
 * 核心与权限层都不硬编码任何具体权限名；新增系统层自带声明即可被收录。
 * 同名权限由两个系统层声明时直接 fail-fast，不静默后者覆盖前者。
 */
final class PermissionRegistry
{
    /** @var array<string, array{name: string, system: string, description: string}> */
    private array $byName = [];

    public function __construct(private string $root)
    {
        foreach (glob($root . '/systems/*/permissions.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data)) {
                throw new RuntimeException('权限声明格式错误：' . $file);
            }
            $system = (string) ($data['system'] ?? basename(dirname($file)));
            foreach ((array) ($data['permissions'] ?? []) as $perm) {
                $name = (string) ($perm['name'] ?? '');
                if ($name === '') {
                    throw new RuntimeException('权限点缺少 name：' . $file);
                }
                if (isset($this->byName[$name])) {
                    throw new RuntimeException(
                        '权限声明冲突：' . $name . '（' . $this->byName[$name]['system'] . ' vs ' . $system . '）'
                    );
                }
                $this->byName[$name] = [
                    'name'        => $name,
                    'system'      => $system,
                    'description' => (string) ($perm['description'] ?? ''),
                ];
            }
        }
        ksort($this->byName);
    }

    public function role(): string { return 'permission_registry'; }

    public function has(string $name): bool { return isset($this->byName[$name]); }

    /** @return array<string, array{name: string, system: string, description: string}> */
    public function all(): array { return $this->byName; }

    /** @return array<string, int> 按系统层统计权限点数 */
    public function bySystem(): array
    {
        $out = [];
        foreach ($this->byName as $perm) {
            $out[$perm['system']] = ($out[$perm['system']] ?? 0) + 1;
        }
        ksort($out);
        return $out;
    }
}
