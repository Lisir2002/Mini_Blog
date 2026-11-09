<?php
declare(strict_types=1);

use Wx\Contracts\DatabaseInterface;
use Wx\Contracts\LoggerInterface;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Systems\Permission\AssemblyGrantRegistrar;
use Wx\Systems\Permission\ContractOverrideLedger;

echo "\n=== 15e. 契约覆盖默认安全：系统层自动登记，第三方必须授权 ===\n";

check('默认配置开启 require_grant，对账接口如实报告', function () use ($root): void {
    $config = require $root . '/config/app.php';
    truthy(($config['permission']['contract_override']['require_grant'] ?? null) === true,
        '默认配置未开启装配期授权门槛');

    $r = adminKernel()->handle(new Request('GET', '/api/v1/permission/overrides', [], adminHeaders()));
    $body = (array) json_decode($r->body(), true);
    truthy(($body['data']['require_grant'] ?? null) === true, '接口未报告 require_grant=true');
    truthy(is_array($body['data']['unauthorized'] ?? null), '缺少未授权覆盖清单');
});

check('自动登记只认已注册系统层，前缀本身不构成例外', function () use ($root): void {
    $k = adminKernel();
    $k->boot();
    $db = $k->container()->resolve(DatabaseInterface::class);
    $ledger = new ContractOverrideLedger($db);
    $registrar = new AssemblyGrantRegistrar($ledger);
    $known = array_keys($k->systems()->manifests());
    $contracts = ['t15e:c1', 't15e:c2', 't15e:c3', 't15e:c4'];
    $overrides = [
        ['contract' => 't15e:c1', 'to' => 'system:log'],
        ['contract' => 't15e:c2', 'to' => 'system:ghost'],
        ['contract' => 't15e:c3', 'to' => 'plugin:evil'],
        ['contract' => 't15e:c4', 'to' => 'theme:bare'],
    ];
    try {
        $done = $registrar->register($overrides, $known);
        truthy(count($done) === 1 && $done[0]['contract'] === 't15e:c1', '自动登记范围错误：' . json_encode($done));
        truthy($ledger->isAuthorized('t15e:c1', 'system:log'), '已注册系统层覆盖未登记');
        truthy(!$ledger->isAuthorized('t15e:c2', 'system:ghost'), '冒充不存在的系统层被放行');
        truthy(!$ledger->isAuthorized('t15e:c3', 'plugin:evil'), '插件覆盖被隐式放行');
        truthy(!$ledger->isAuthorized('t15e:c4', 'theme:bare'), '主题覆盖被隐式放行');
    } finally {
        foreach ($contracts as $contract) {
            $db->pdo()->prepare(
                'DELETE FROM ' . $db->prefix() . 'permission_contract_overrides WHERE contract = ?'
            )->execute([$contract]);
        }
    }
});

check('默认配置下第三方未授权覆盖 fail-closed，公开页不锁死', function () use ($root): void {
    $k = new Kernel($root);
    $k->boot();
    $threw = false;
    try {
        $k->container()->singleton(
            LoggerInterface::class, static fn(): object => new stdClass(), 'plugin:evil', 99
        );
    } catch (RuntimeException $e) {
        $threw = str_contains($e->getMessage(), '契约覆盖被权限策略拒绝');
    }
    truthy($threw, '默认配置下插件覆盖未被拒绝');
    truthy($k->container()->ownerOf(LoggerInterface::class) !== 'plugin:evil', '被拒覆盖仍写入了容器');
    truthy(str_contains($k->handle(new Request('GET', '/'))->body(), '站点首页'), '公开首页被锁死');
});

check('已登记授权后第三方覆盖放行，撤销后重新拒绝', function () use ($root): void {
    $k0 = adminKernel();
    $k0->boot();
    $db = $k0->container()->resolve(DatabaseInterface::class);
    $ledger = new ContractOverrideLedger($db);
    $contract = LoggerInterface::class;
    $owner = 'plugin:t15e';
    try {
        $ledger->grant($contract, $owner, 'admin', '15e 守卫', true);
        $k = new Kernel($root);
        $k->boot();
        $k->container()->singleton($contract, static fn(): object => new stdClass(), $owner, 99);
        truthy($k->container()->ownerOf($contract) === $owner, '已授权覆盖未生效');

        $ledger->grant($contract, $owner, 'admin', '15e 守卫撤销', false);
        $k2 = new Kernel($root);
        $k2->boot();
        $threw = false;
        try {
            $k2->container()->singleton($contract, static fn(): object => new stdClass(), $owner, 99);
        } catch (RuntimeException) {
            $threw = true;
        }
        truthy($threw, '撤销授权后覆盖未被拒绝');
    } finally {
        $db->pdo()->prepare(
            'DELETE FROM ' . $db->prefix() . 'permission_contract_overrides WHERE contract = ? AND owner = ?'
        )->execute([$contract, $owner]);
    }
});
