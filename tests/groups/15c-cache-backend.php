<?php
declare(strict_types=1);

use Wx\Contracts\AccessControlInterface;
use Wx\Systems\Lifecycle\Fs;
use Wx\Systems\Permission\FileCacheBackend;
use Wx\Systems\Permission\PermissionCache;

echo "\n=== 15c. 缓存后端可替换：跨实例共享，语义不变 ===\n";

check('file 后端跨实例命中，flush 推进 epoch 后立即失效', function (): void {
    $dir = sys_get_temp_dir() . '/wx-cache-15c-' . bin2hex(random_bytes(4));
    try {
        $a = new PermissionCache(true, new FileCacheBackend($dir));
        truthy($a->remember('k', static fn(): array => ['v' => 1]) === ['v' => 1], '首次写入失败');

        // 第二个实例模拟「另一个请求 / 另一个进程」
        $b = new PermissionCache(true, new FileCacheBackend($dir));
        truthy($b->remember('k', static fn(): array => ['v' => 2]) === ['v' => 1],
            '未跨实例命中共享缓存');
        truthy(($b->stats()['backend']['driver'] ?? '') === 'file', '未报告 file 后端');

        $b->flush();
        $c = new PermissionCache(true, new FileCacheBackend($dir));
        truthy($c->remember('k', static fn(): array => ['v' => 3]) === ['v' => 3],
            'flush 后仍读到旧值（epoch 未生效）');
    } finally {
        Fs::removeDir($dir);
    }
});

check('关闭缓存 / 换后端时决策逐项一致', function (): void {
    $on  = adminKernel(['permission' => ['cache' => ['enabled' => true,  'driver' => 'memory']]]);
    $off = adminKernel(['permission' => ['cache' => ['enabled' => false, 'driver' => 'memory']]]);
    $on->boot();
    $off->boot();
    $a = $on->container()->resolve(AccessControlInterface::class);
    $b = $off->container()->resolve(AccessControlInterface::class);

    $identities = [
        'anonymous'  => null,
        'admin'      => ['role' => 'admin', 'username' => 'admin', 'user_id' => 1],
        'author'     => ['role' => 'author', 'username' => 'author', 'user_id' => 9],
        'subscriber' => ['role' => 'subscriber', 'username' => 'sub', 'user_id' => 8],
    ];
    $actions = ['content.read', 'content.create', 'content.delete', 'permission.manage', 'page.console.read'];
    foreach ($identities as $label => $identity) {
        foreach ($actions as $action) {
            $x = $a->decide($identity, $action);
            $y = $b->decide($identity, $action);
            truthy($x === $y, "缓存开关改变了决策：{$label}/{$action} → {$x} vs {$y}");
        }
    }
});

check('file 后端下策略钩子仍每次执行，不被缓存绕过', function (): void {
    $k = adminKernel(['permission' => ['cache' => ['enabled' => true, 'driver' => 'file']]]);
    $k->boot();
    $acl = $k->container()->resolve(AccessControlInterface::class);
    $admin = ['role' => 'admin', 'username' => 'admin', 'user_id' => 1];

    truthy($acl->allows($admin, 'content.read'), '前置条件失败');
    $k->hooks()->filter('permission.decide', static function (string $v): string {
        return $v === AccessControlInterface::ALLOW ? AccessControlInterface::DENY : $v;
    }, 99);
    truthy(!$acl->allows($admin, 'content.read'), '共享缓存绕过了请求级收紧策略');
});

check('缓存只缓存基础数据，不缓存最终决策', function (): void {
    $k = adminKernel(['permission' => ['cache' => ['enabled' => true, 'driver' => 'memory']]]);
    $k->boot();
    $acl = $k->container()->resolve(AccessControlInterface::class);
    $admin = ['role' => 'admin', 'username' => 'admin', 'user_id' => 1];
    $acl->allows($admin, 'content.read');
    $before = $acl->cacheStats();
    $acl->allows($admin, 'content.read');
    $after = $acl->cacheStats();
    truthy(($after['hits'] ?? 0) > ($before['hits'] ?? 0), '基础数据读取未命中请求内缓存');
    truthy(($after['backend']['driver'] ?? '') === 'memory', '未报告内存后端');
});
